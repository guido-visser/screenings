<template>
	<div ref="container"
		class="player"
		:class="{ 'player--idle': idle && playing, 'player--fullscreen': fullscreen }"
		tabindex="0"
		@contextmenu.prevent
		@dragstart.prevent
		@pointermove="wake"
		@pointerdown="wake"
		@keydown="onKey">
		<video ref="video"
			class="player__video"
			playsinline
			preload="metadata"
			disablepictureinpicture
			disableremoteplayback
			controlslist="nodownload noremoteplayback noplaybackrate"
			@click="togglePlay"
			@dblclick="toggleFullscreen"
			@loadedmetadata="onLoadedMetadata"
			@play="onPlay"
			@pause="onPause"
			@ended="onEnded"
			@timeupdate="onTimeUpdate"
			@progress="onProgress"
			@durationchange="onDurationChange"
			@volumechange="onVolumeChange"
			@seeking="onSeeking"
			@seeked="onSeeked"
			@error="onVideoError"
			@waiting="onWaiting"
			@playing="onBufferingEnd"
			@canplay="onBufferingEnd">
			<track v-for="subtitle in playback.subtitles"
				:id="'subtitle-' + subtitle.id"
				:key="subtitle.id"
				kind="subtitles"
				:src="subtitle.src"
				:srclang="subtitle.lang"
				:label="subtitle.label">
		</video>

		<div v-if="watermark" class="player__watermark" :style="watermarkStyle" aria-hidden="true">
			{{ watermark }}
		</div>

		<div v-if="buffering && !stopReason" class="player__spinner" aria-hidden="true" />

		<dialog v-if="resumeAt !== null"
			ref="resumeDialog"
			class="player__resume"
			aria-labelledby="player-resume-title"
			@cancel.prevent>
			<h2 id="player-resume-title">{{ t('screenings', 'Welcome back') }}</h2>
			<p>{{ t('screenings', 'Do you want to start over, or continue where you left off?') }}</p>
			<p class="player__resume-at">{{ t('screenings', 'You stopped at {time}.', { time: formatTime(resumeAt) }) }}</p>
			<div class="player__resume-actions">
				<button class="player__resume-button" @click="startOver">
					{{ t('screenings', 'Start over') }}
				</button>
				<button class="player__resume-button player__resume-button--primary" autofocus @click="continueWatching">
					{{ t('screenings', 'Continue where I left off') }}
				</button>
			</div>
		</dialog>

		<button v-if="!playing && !stopReason && !started && !asking && !noting"
			class="player__big-play"
			:aria-label="t('screenings', 'Play')"
			@click="togglePlay">
			<svg viewBox="0 0 24 24"><path :d="mdiPlay" /></svg>
		</button>

		<form v-if="noting"
			class="player__note"
			@submit.prevent="saveNote"
			@click.stop
			@keydown.stop="onNoteKey">
			<label for="player-note-text" class="player__note-label">
				{{ t('screenings', 'Note at {time}', { time: formatTime(noteAt) }) }}
			</label>
			<textarea id="player-note-text"
				ref="noteInput"
				v-model="noteText"
				rows="3"
				maxlength="2000"
				:placeholder="t('screenings', 'What do you notice at this moment?')" />
			<p v-if="noteError" class="player__note-error" role="alert">
				{{ noteError }}
			</p>
			<div class="player__note-actions">
				<span class="player__note-hint">{{ t('screenings', 'Enter saves, Shift+Enter starts a new line') }}</span>
				<button type="button" class="player__note-button" @click="closeNote">
					{{ t('screenings', 'Cancel') }}
				</button>
				<button type="submit" class="player__note-button player__note-button--primary" :disabled="savingNote || !noteText.trim()">
					{{ t('screenings', 'Save note') }}
				</button>
			</div>
		</form>
		<p v-if="noteSaved" class="player__note-saved" role="status">
			{{ t('screenings', 'Note saved') }}
		</p>

		<div v-if="stopReason" class="player__stopped" role="alert">
			<p>{{ stopMessage }}</p>
			<button v-if="stopReason !== 'revoked' && stopReason !== 'expired'" class="player__reload" @click="reload">
				{{ t('screenings', 'Reload') }}
			</button>
		</div>

		<div v-if="!stopReason" class="player__controls" @click.stop>
			<div class="player__seek">
				<div class="player__buffered" :style="{ width: bufferedPercent + '%' }" />
				<input type="range"
					min="0"
					:max="duration || 0"
					step="0.1"
					:value="currentTime"
					:aria-label="t('screenings', 'Position')"
					:style="{ '--progress': progressPercent + '%' }"
					@input="seekTo($event.target.valueAsNumber)">
				<div v-if="notes?.enabled && duration" class="player__markers" aria-hidden="true">
					<span v-for="note in notes.items"
						:key="note.id"
						class="player__marker"
						:style="{ left: Math.min(100, (note.position / duration) * 100) + '%' }" />
				</div>
			</div>
			<div class="player__bar">
				<button class="player__button" :aria-label="playing ? t('screenings', 'Pause') : t('screenings', 'Play')" @click="togglePlay">
					<svg viewBox="0 0 24 24"><path :d="playing ? mdiPause : mdiPlay" /></svg>
				</button>
				<button class="player__button player__button--skip" :aria-label="t('screenings', 'Back 10 seconds')" @click="seekBy(-10)">
					<svg viewBox="0 0 24 24"><path :d="mdiRewind10" /></svg>
				</button>
				<button class="player__button player__button--skip" :aria-label="t('screenings', 'Forward 10 seconds')" @click="seekBy(10)">
					<svg viewBox="0 0 24 24"><path :d="mdiFastForward10" /></svg>
				</button>
				<button class="player__button" :aria-label="muted ? t('screenings', 'Unmute') : t('screenings', 'Mute')" @click="toggleMute">
					<svg viewBox="0 0 24 24"><path :d="muted || volume === 0 ? mdiVolumeOff : mdiVolumeHigh" /></svg>
				</button>
				<input class="player__volume"
					type="range"
					min="0"
					max="1"
					step="0.05"
					:value="muted ? 0 : volume"
					:aria-label="t('screenings', 'Volume')"
					@input="setVolume($event.target.valueAsNumber)">
				<span class="player__time">{{ formatTime(currentTime) }} / {{ formatTime(duration) }}</span>
				<span class="player__spacer" />
				<label v-if="playback.subtitles.length" class="player__select">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiSubtitlesOutline" /></svg>
					<select :value="subtitle" :aria-label="t('screenings', 'Subtitles')" @change="setSubtitle($event.target.value)">
						<option value="">{{ t('screenings', 'Off') }}</option>
						<option v-for="s in playback.subtitles" :key="s.id" :value="String(s.id)">
							{{ s.label }}
						</option>
					</select>
				</label>
				<label v-if="qualities.length > 1" class="player__select">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiCogOutline" /></svg>
					<select :value="quality" :aria-label="t('screenings', 'Quality')" @change="setQuality($event.target.value)">
						<option value="auto">{{ autoLabel }}</option>
						<option v-for="q in qualities" :key="q.index" :value="String(q.index)">
							{{ q.label }}
						</option>
					</select>
				</label>
				<button v-if="notes?.enabled"
					class="player__button"
					:aria-label="t('screenings', 'Add a note at this moment')"
					:title="t('screenings', 'Add a note at this moment (N)')"
					@click="openNote">
					<svg viewBox="0 0 24 24"><path :d="mdiCommentPlusOutline" /></svg>
				</button>
				<button class="player__button" :aria-label="fullscreen ? t('screenings', 'Exit full screen') : t('screenings', 'Full screen')" @click="toggleFullscreen">
					<svg viewBox="0 0 24 24"><path :d="fullscreen ? mdiFullscreenExit : mdiFullscreen" /></svg>
				</button>
			</div>
		</div>
	</div>
</template>

<script setup>
import {
	mdiCogOutline,
	mdiCommentPlusOutline,
	mdiFastForward10,
	mdiFullscreen,
	mdiFullscreenExit,
	mdiPause,
	mdiPlay,
	mdiRewind10,
	mdiSubtitlesOutline,
	mdiVolumeHigh,
	mdiVolumeOff,
} from '@mdi/js'
import { t } from '@nextcloud/l10n'
import Hls from 'hls.js'
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { formatTime, Heartbeat, RangeRecorder, Tracker } from '../playback.js'

const props = defineProps({
	playback: { type: Object, required: true },
	screening: { type: Object, required: true },
	viewer: { type: Object, required: true },
	/** The viewer's notes (see notes.js), or null. */
	notes: { type: Object, default: null },
})
const emit = defineEmits(['ended'])

const container = ref(null)
const video = ref(null)

const playing = ref(false)
const started = ref(false)
const buffering = ref(false)
const currentTime = ref(0)
const duration = ref((props.screening.durationMs ?? 0) / 1000)
const bufferedEnd = ref(0)
const volume = ref(1)
const muted = ref(false)
// The chosen track's id ('' = off). Several tracks may share a language
// (e.g. "English" and "English (SDH)"); tracking still reports the language.
const subtitle = ref(String(props.playback.subtitles.find((s) => s.isDefault)?.id ?? ''))
const subtitleLang = (id) => props.playback.subtitles.find((s) => String(s.id) === id)?.lang ?? null
const qualities = ref([])
const quality = ref('auto')
const currentLevel = ref(-1)
const fullscreen = ref(false)
const idle = ref(false)
const stopReason = ref(null)
// Where this invite stopped last time (from the server); the viewer chooses
// between that and the beginning before anything plays.
const resumeAt = props.playback.resumeAt ?? null
const resumeDialog = ref(null)
const asking = ref(resumeAt !== null)

let hls = null
let heartbeat = null
let tracker = null
let idleTimer = null
let watermarkTimer = null
let volumeTimer = null
const ranges = new RangeRecorder()
// Playhead before a seek started, for the "seeked" event.
let seekFrom = null
let bufferingSince = null
let hiddenWhilePlaying = false
let ready = false
// Direct MP4: a seek target already reported to the server, before the
// browser moves there (see seekTo), and recoveries from refused ranges.
let pendingSeek = null
let seekSeq = 0
let mp4Recoveries = 0
/** Seeks closer than this stay inside the server's playhead window anyway. */
const MP4_NEAR_SECONDS = 60
const MP4_MAX_RECOVERIES = 3
/** While playing, the controls hide after this long without pointer movement. */
const IDLE_MS = 3000

const position = () => video.value?.currentTime ?? 0

const progressPercent = computed(() => duration.value ? (currentTime.value / duration.value) * 100 : 0)
const bufferedPercent = computed(() => duration.value ? Math.min(100, (bufferedEnd.value / duration.value) * 100) : 0)
const autoLabel = computed(() => {
	const level = qualities.value.find((q) => q.index === currentLevel.value)
	return level && quality.value === 'auto'
		? t('screenings', 'Auto ({quality})', { quality: level.label })
		: t('screenings', 'Auto')
})

const stopMessages = {
	replaced: t('screenings', 'This screening was opened in another window or on another device, so playback stopped here.'),
	revoked: t('screenings', 'The sender has withdrawn access to this screening.'),
	expired: t('screenings', 'This screening has ended.'),
	stale: t('screenings', 'Playback stopped because the connection to the server was interrupted.'),
	unsupported: t('screenings', 'This browser can\'t play the screening. Use a recent version of Chrome, Firefox, Edge or Safari.'),
	watermark: t('screenings', 'Playback stopped because the page was modified.'),
}
const stopMessage = computed(() => stopMessages[stopReason.value]
	?? t('screenings', 'Playback stopped. Reload the page to continue.'))

// --- Watermark --------------------------------------------------------------

const watermark = computed(() => props.screening.watermark ? `${props.viewer.name} · ${props.viewer.email}` : null)
const watermarkPosition = ref({ x: 10, y: 12 })
const watermarkStyle = computed(() => ({
	left: watermarkPosition.value.x + '%',
	top: watermarkPosition.value.y + '%',
}))

/** Drifts slowly to a new random spot; also checks it is still there. */
function moveWatermark() {
	watermarkPosition.value = { x: 4 + Math.random() * 56, y: 6 + Math.random() * 76 }
	const node = container.value?.querySelector('.player__watermark')
	const style = node && getComputedStyle(node)
	if (!node || style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) < 0.05) {
		stop('watermark')
	}
}

// --- Notes ------------------------------------------------------------------

const noting = ref(false)
const noteAt = ref(0)
const noteText = ref('')
const noteError = ref(null)
const savingNote = ref(false)
const noteSaved = ref(false)
const noteInput = ref(null)
let resumeAfterNote = false
let noteSavedTimer = null

/** Pauses and opens the note box, stamped with the current moment. */
function openNote() {
	if (!props.notes?.enabled || stopReason.value || asking.value || noting.value) {
		return
	}
	const v = video.value
	resumeAfterNote = Boolean(v && !v.paused && !v.ended)
	v?.pause()
	noteAt.value = position()
	noteText.value = ''
	noteError.value = null
	noting.value = true
	nextTick(() => noteInput.value?.focus())
}

function closeNote() {
	noting.value = false
	container.value?.focus()
	if (resumeAfterNote && !stopReason.value) {
		video.value?.play().catch(() => {})
	}
	resumeAfterNote = false
}

async function saveNote() {
	if (savingNote.value || !noteText.value.trim()) {
		return
	}
	savingNote.value = true
	noteError.value = null
	try {
		await props.notes.add(noteAt.value, noteText.value)
		closeNote()
		noteSaved.value = true
		window.clearTimeout(noteSavedTimer)
		noteSavedTimer = window.setTimeout(() => { noteSaved.value = false }, 2000)
	} catch (error) {
		noteError.value = error.message
	} finally {
		savingNote.value = false
	}
}

// Key presses in the note box stay there (see @keydown.stop).
function onNoteKey(event) {
	if (event.key === 'Escape') {
		event.preventDefault()
		closeNote()
	} else if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
		event.preventDefault()
		saveNote()
	}
}

// --- Playback ---------------------------------------------------------------

function playerState() {
	const v = video.value
	const level = qualities.value.find((q) => q.index === currentLevel.value)
	return {
		position: pendingSeek ?? (v ? v.currentTime : 0),
		playing: v ? !v.paused && !v.ended : false,
		subtitle: subtitleLang(subtitle.value),
		quality: props.playback.mode === 'hls' ? (level?.label ?? 'auto') : 'source',
		ranges: ranges.take(),
	}
}

function stop(reason) {
	if (stopReason.value) {
		return
	}
	ranges.close()
	tracker?.track('playback_stopped', position(), { reason })
	tracker?.flush()
	stopReason.value = reason
	heartbeat?.stop()
	hls?.destroy()
	hls = null
	const v = video.value
	if (v) {
		v.pause()
		v.removeAttribute('src')
		v.load()
	}
	exitFullscreen()
}

function setupHls() {
	if (!Hls.isSupported()) {
		stop('unsupported')
		return
	}
	const retry = { maxNumRetry: 6, retryDelayMs: 750, maxRetryDelayMs: 6000 }
	hls = new Hls({
		// Every playlist, key and segment request carries the current token.
		xhrSetup: (xhr) => xhr.setRequestHeader(props.playback.tokenHeader, heartbeat.token),
		maxBufferLength: 30,
		maxMaxBufferLength: 45,
		backBufferLength: 60,
		capLevelToPlayerSize: true,
		fragLoadPolicy: { default: { maxTimeToFirstByteMs: 10000, maxLoadTimeMs: 60000, timeoutRetry: retry, errorRetry: retry } },
		keyLoadPolicy: { default: { maxTimeToFirstByteMs: 8000, maxLoadTimeMs: 20000, timeoutRetry: retry, errorRetry: retry } },
	})
	hls.on(Hls.Events.MANIFEST_PARSED, () => {
		qualities.value = hls.levels
			.map((level, index) => ({ index, height: level.height, label: level.height ? `${level.height}p` : `${Math.round(level.bitrate / 1000)} kbit/s` }))
			.sort((a, b) => b.height - a.height)
	})
	hls.on(Hls.Events.LEVEL_SWITCHED, (_, data) => {
		const from = levelLabel(currentLevel.value)
		currentLevel.value = data.level
		// Automatic switches; manual ones are tracked in setQuality().
		if (quality.value === 'auto' && from !== null) {
			tracker.track('quality_change', position(), { from, to: levelLabel(data.level), auto: true })
		}
	})
	hls.on(Hls.Events.ERROR, async (_, data) => {
		if (!data.fatal) {
			return
		}
		tracker.track('error', position(), { reason: String(data.details ?? data.type) })
		// Ask the server why (revoked, replaced, …); otherwise a generic stop.
		await heartbeat.beat()
		stop(stopReason.value ?? 'error')
	})
	hls.loadSource(props.playback.src)
	hls.attachMedia(video.value)
}

function applySubtitle() {
	for (const track of video.value.textTracks) {
		track.mode = subtitle.value !== '' && track.id === 'subtitle-' + subtitle.value ? 'showing' : 'disabled'
	}
}

function setSubtitle(id) {
	tracker.track('subtitle_change', position(), { from: subtitleLang(subtitle.value), to: subtitleLang(id) })
	subtitle.value = id
	applySubtitle()
	heartbeat.beat()
}

function levelLabel(index) {
	return qualities.value.find((q) => q.index === index)?.label ?? null
}

function setQuality(value) {
	tracker.track('quality_change', position(), {
		from: quality.value === 'auto' ? 'auto' : levelLabel(Number(quality.value)),
		to: value === 'auto' ? 'auto' : levelLabel(Number(value)),
		auto: false,
	})
	quality.value = value
	if (hls) {
		hls.currentLevel = value === 'auto' ? -1 : Number(value)
	}
}

function togglePlay() {
	const v = video.value
	if (stopReason.value || !v) {
		return
	}
	if (v.paused || v.ended) {
		started.value = true
		v.play().catch(() => {})
	} else {
		v.pause()
	}
}

async function seekTo(seconds) {
	const v = video.value
	if (!v || !Number.isFinite(seconds)) {
		return
	}
	const target = Math.max(0, Math.min(seconds, duration.value || seconds))
	const seq = ++seekSeq
	if (props.playback.mode !== 'hls' && Math.abs(target - v.currentTime) > MP4_NEAR_SECONDS) {
		// The browser fetches the new position at once and, unlike hls.js,
		// never retries a refused range. So tell the server first.
		pendingSeek = target
		currentTime.value = target
		await heartbeat.beatFresh()
		if (seq !== seekSeq || stopReason.value) {
			// A newer seek (e.g. still dragging) takes over.
			return
		}
		pendingSeek = null
	}
	v.currentTime = target
}

/**
 * Direct MP4 only: a refused range (a seek that raced its heartbeat) ends the
 * browser's media pipeline for good. Reload the source and continue at the
 * same spot, a few times at most.
 */
async function onVideoError() {
	const v = video.value
	if (props.playback.mode === 'hls' || stopReason.value || !v?.error) {
		return
	}
	if (mp4Recoveries >= MP4_MAX_RECOVERIES) {
		tracker.track('error', position(), { reason: 'media_error_' + v.error.code })
		stop('error')
		return
	}
	mp4Recoveries++
	const at = pendingSeek ?? currentTime.value
	const resume = playing.value
	pendingSeek = at
	await heartbeat.beatFresh()
	pendingSeek = null
	if (stopReason.value) {
		return
	}
	v.addEventListener('loadedmetadata', () => {
		v.currentTime = at
		if (resume) {
			v.play().catch(() => {})
		}
	}, { once: true })
	v.src = props.playback.src
}

function closeResumeDialog() {
	asking.value = false
	resumeDialog.value?.close()
}

function startOver() {
	closeResumeDialog()
	// Still inside the click, so the browser allows playback with sound.
	togglePlay()
}

async function continueWatching() {
	closeResumeDialog()
	const v = video.value
	if (stopReason.value || !v) {
		return
	}
	started.value = true
	currentTime.value = resumeAt
	// A seek before the player knows the film can get lost (hls.js).
	if (v.readyState < HTMLMediaElement.HAVE_METADATA) {
		await new Promise((resolve) => v.addEventListener('loadedmetadata', resolve, { once: true }))
	}
	// seekTo() tells the server about the jump before the player fetches there.
	await seekTo(resumeAt)
	if (stopReason.value) {
		return
	}
	// Some browsers (Safari) only allow sound right inside the click; then
	// the big play button comes back.
	v.play().catch(() => {
		started.value = false
	})
}

function seekBy(delta) {
	seekTo((video.value?.currentTime ?? 0) + delta)
}

function onSeeking() {
	if (seekFrom === null) {
		seekFrom = ranges.last ?? currentTime.value
	}
	ranges.close()
	currentTime.value = video.value.currentTime
	// Report the target straight away so the server lets the player fetch there.
	heartbeat.beat()
}

function onSeeked() {
	const to = position()
	tracker.track('seeked', to, { from: Math.round((seekFrom ?? 0) * 10) / 10, to: Math.round(to * 10) / 10 })
	seekFrom = null
	mp4Recoveries = 0
}

function onLoadedMetadata() {
	if (!ready) {
		ready = true
		tracker.track('player_ready', 0, { screen: [window.screen.width, window.screen.height], mode: props.playback.mode })
	}
}

function onPlay() {
	playing.value = true
	tracker.track('play', position())
}

function onPause() {
	playing.value = false
	ranges.close()
	// "ended" also pauses; that is tracked separately. A media error pauses
	// too, but the viewer didn't.
	if (!video.value.ended && !video.value.error) {
		tracker.track('pause', position())
	}
}

function onEnded() {
	playing.value = false
	ranges.close()
	tracker.track('ended', position())
	emit('ended')
}

function onWaiting() {
	buffering.value = true
	ranges.close()
	// Buffering right after a seek or before the first play isn't a stall.
	if (started.value && seekFrom === null && bufferingSince === null) {
		bufferingSince = Date.now()
		tracker.track('buffering_start', position())
	}
}

function onBufferingEnd() {
	buffering.value = false
	if (bufferingSince !== null) {
		tracker.track('buffering_end', position(), { seconds: Math.round((Date.now() - bufferingSince) / 100) / 10 })
		bufferingSince = null
	}
}

function toggleMute() {
	video.value.muted = !video.value.muted
	if (!video.value.muted && video.value.volume === 0) {
		video.value.volume = 0.5
	}
}

function setVolume(value) {
	video.value.volume = value
	video.value.muted = value === 0
}

function onTimeUpdate() {
	const v = video.value
	currentTime.value = v.currentTime
	if (!v.paused && !v.seeking && !buffering.value) {
		ranges.update(v.currentTime)
	}
}

function onProgress() {
	const v = video.value
	for (let i = 0; i < v.buffered.length; i++) {
		if (v.buffered.start(i) <= v.currentTime + 0.5 && v.buffered.end(i) >= v.currentTime) {
			bufferedEnd.value = v.buffered.end(i)
			return
		}
	}
}

function onDurationChange() {
	if (Number.isFinite(video.value.duration) && video.value.duration > 0) {
		duration.value = video.value.duration
	}
}

function onVolumeChange() {
	volume.value = video.value.volume
	muted.value = video.value.muted
	// Dragging the slider fires many events; record where it settles.
	window.clearTimeout(volumeTimer)
	volumeTimer = window.setTimeout(() => {
		tracker.track('volume_change', position(), { volume: Math.round(volume.value * 100) / 100, muted: muted.value })
	}, 1000)
}

// --- Full screen, idle UI, keyboard ------------------------------------------

// Safari before 16.4 (and older iPadOS) only has the webkit-prefixed API.
const fullscreenElement = () => document.fullscreenElement ?? document.webkitFullscreenElement ?? null

function toggleFullscreen() {
	const el = container.value
	if (fullscreen.value) {
		exitFullscreen()
	} else if (el.requestFullscreen) {
		// The whole player, so the watermark and controls stay visible.
		el.requestFullscreen().catch(() => {})
	} else if (el.webkitRequestFullscreen) {
		el.webkitRequestFullscreen()
	} else if (video.value.webkitEnterFullscreen) {
		// iPhone: only the video element can go full screen, with the
		// system's own player; give it its controls (see onNativeFullscreen).
		video.value.controls = true
		video.value.webkitEnterFullscreen()
	}
}

function exitFullscreen() {
	if (document.fullscreenElement) {
		document.exitFullscreen().catch(() => {})
	} else if (document.webkitFullscreenElement) {
		document.webkitExitFullscreen()
	}
}

function onFullscreenChange() {
	const now = fullscreenElement() === container.value
	if (now !== fullscreen.value) {
		tracker.track(now ? 'fullscreen_enter' : 'fullscreen_exit', position())
	}
	fullscreen.value = now
	// Show the controls on the new screen, then let them fade as usual.
	wake()
}

/** iPhone's native full screen player (webkitbeginfullscreen/webkitendfullscreen). */
function onNativeFullscreen(event) {
	const now = event.type === 'webkitbeginfullscreen'
	tracker.track(now ? 'fullscreen_enter' : 'fullscreen_exit', position())
	// Back on the page, our own controls take over again.
	video.value.controls = now
}

// --- Page-level tracking -------------------------------------------------------

/**
 * Tab switches only matter while the film plays: then the viewer surely
 * isn't watching. The return is tracked only to close such a switch.
 */
function onVisibilityChange() {
	if (document.visibilityState === 'hidden') {
		if (playing.value) {
			hiddenWhilePlaying = true
			tracker.track('visibility_hidden', position())
		}
		// Mobile browsers may never fire pagehide (a tab closed from the
		// switcher, a killed app); send what we have now, the playhead too,
		// so "continue where you left off" knows where it was.
		tracker.flush(true)
		heartbeat.final()
	} else if (hiddenWhilePlaying) {
		hiddenWhilePlaying = false
		tracker.track('visibility_visible', position())
	}
}

function onPageHide() {
	ranges.close()
	tracker.track('page_leave', position())
	heartbeat.final()
	tracker.stop()
}

function onPageShow(event) {
	// Restored from the back/forward cache: timers and the token are stale,
	// so start a fresh session.
	if (event.persisted) {
		window.location.reload()
	}
}

function wake() {
	idle.value = false
	window.clearTimeout(idleTimer)
	idleTimer = window.setTimeout(() => {
		// Keep the controls while the pointer rests on them.
		if (container.value?.querySelector('.player__controls:hover')) {
			wake()
			return
		}
		idle.value = true
	}, IDLE_MS)
}

/**
 * In fullscreen, any pointer movement shows the controls, wherever the
 * event lands (the player's own listeners only see events inside it).
 */
function onDocumentPointer() {
	if (fullscreen.value) {
		wake()
	}
}

function onKey(event) {
	// The resume dialog sits inside the player, so its key presses bubble here.
	if (event.target instanceof HTMLSelectElement || stopReason.value || asking.value) {
		return
	}
	const actions = {
		' ': togglePlay,
		k: togglePlay,
		ArrowLeft: () => seekBy(-5),
		ArrowRight: () => seekBy(5),
		j: () => seekBy(-10),
		l: () => seekBy(10),
		f: toggleFullscreen,
		m: toggleMute,
		n: openNote,
	}
	const action = actions[event.key]
	if (action && !(event.target instanceof HTMLInputElement && event.key.startsWith('Arrow'))) {
		event.preventDefault()
		action()
		wake()
	}
}

function reload() {
	window.location.reload()
}

// The notes list under the player jumps through here.
defineExpose({ seekTo })

onMounted(() => {
	tracker = new Tracker(props.playback.eventsUrl)
	heartbeat = new Heartbeat(props.playback, playerState, stop, (state) => ranges.restore(state.ranges))
	heartbeat.start()
	document.addEventListener('visibilitychange', onVisibilityChange)
	window.addEventListener('pagehide', onPageHide)
	window.addEventListener('pageshow', onPageShow)

	if (props.playback.mode === 'hls') {
		setupHls()
	} else {
		video.value.src = props.playback.src
	}

	// Tracks load lazily; set the default one once they exist.
	video.value.textTracks.addEventListener('addtrack', applySubtitle)
	applySubtitle()

	if (watermark.value) {
		moveWatermark()
		watermarkTimer = window.setInterval(moveWatermark, 15000)
	}
	document.addEventListener('fullscreenchange', onFullscreenChange)
	document.addEventListener('pointermove', onDocumentPointer, { capture: true, passive: true })
	document.addEventListener('mousemove', onDocumentPointer, { capture: true, passive: true })
	document.addEventListener('webkitfullscreenchange', onFullscreenChange)
	video.value.addEventListener('webkitbeginfullscreen', onNativeFullscreen)
	video.value.addEventListener('webkitendfullscreen', onNativeFullscreen)
	wake()
	resumeDialog.value?.showModal()
})

onBeforeUnmount(() => {
	heartbeat?.stop()
	tracker?.stop()
	hls?.destroy()
	window.clearInterval(watermarkTimer)
	window.clearTimeout(idleTimer)
	window.clearTimeout(volumeTimer)
	window.clearTimeout(noteSavedTimer)
	document.removeEventListener('fullscreenchange', onFullscreenChange)
	document.removeEventListener('pointermove', onDocumentPointer, { capture: true })
	document.removeEventListener('mousemove', onDocumentPointer, { capture: true })
	document.removeEventListener('webkitfullscreenchange', onFullscreenChange)
	document.removeEventListener('visibilitychange', onVisibilityChange)
	window.removeEventListener('pagehide', onPageHide)
	window.removeEventListener('pageshow', onPageShow)
})
</script>

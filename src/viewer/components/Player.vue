<template>
	<div ref="container"
		class="player"
		:class="{ 'player--idle': idle && playing, 'player--fullscreen': fullscreen }"
		tabindex="0"
		@contextmenu.prevent
		@dragstart.prevent
		@mousemove="wake"
		@touchstart.passive="wake"
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
			@play="playing = true"
			@pause="playing = false"
			@ended="playing = false"
			@timeupdate="onTimeUpdate"
			@progress="onProgress"
			@durationchange="onDurationChange"
			@volumechange="onVolumeChange"
			@seeking="onSeeking"
			@waiting="buffering = true"
			@playing="buffering = false"
			@canplay="buffering = false">
			<track v-for="subtitle in playback.subtitles"
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

		<button v-if="!playing && !stopReason && !started"
			class="player__big-play"
			:aria-label="t('screenings', 'Play')"
			@click="togglePlay">
			<svg viewBox="0 0 24 24"><path :d="mdiPlay" /></svg>
		</button>

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
			</div>
			<div class="player__bar">
				<button class="player__button" :aria-label="playing ? t('screenings', 'Pause') : t('screenings', 'Play')" @click="togglePlay">
					<svg viewBox="0 0 24 24"><path :d="playing ? mdiPause : mdiPlay" /></svg>
				</button>
				<button class="player__button" :aria-label="t('screenings', 'Back 10 seconds')" @click="seekBy(-10)">
					<svg viewBox="0 0 24 24"><path :d="mdiRewind10" /></svg>
				</button>
				<button class="player__button" :aria-label="t('screenings', 'Forward 10 seconds')" @click="seekBy(10)">
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
						<option v-for="s in playback.subtitles" :key="s.id" :value="s.lang">
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
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { formatTime, Heartbeat } from '../playback.js'

const props = defineProps({
	playback: { type: Object, required: true },
	screening: { type: Object, required: true },
	viewer: { type: Object, required: true },
})

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
const subtitle = ref(props.playback.subtitles.find((s) => s.isDefault)?.lang ?? '')
const qualities = ref([])
const quality = ref('auto')
const currentLevel = ref(-1)
const fullscreen = ref(false)
const idle = ref(false)
const stopReason = ref(null)

let hls = null
let heartbeat = null
let idleTimer = null
let watermarkTimer = null

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

// --- Playback ---------------------------------------------------------------

function playerState() {
	const v = video.value
	const level = qualities.value.find((q) => q.index === currentLevel.value)
	return {
		position: v ? v.currentTime : 0,
		playing: v ? !v.paused && !v.ended : false,
		subtitle: subtitle.value || null,
		quality: props.playback.mode === 'hls' ? (level?.label ?? 'auto') : 'source',
	}
}

function stop(reason) {
	if (stopReason.value) {
		return
	}
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
		currentLevel.value = data.level
	})
	hls.on(Hls.Events.ERROR, async (_, data) => {
		if (!data.fatal) {
			return
		}
		// Ask the server why (revoked, replaced, …); otherwise a generic stop.
		await heartbeat.beat()
		stop(stopReason.value ?? 'error')
	})
	hls.loadSource(props.playback.src)
	hls.attachMedia(video.value)
}

function applySubtitle() {
	for (const track of video.value.textTracks) {
		track.mode = track.language === subtitle.value ? 'showing' : 'disabled'
	}
}

function setSubtitle(lang) {
	subtitle.value = lang
	applySubtitle()
	heartbeat.beat()
}

function setQuality(value) {
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

function seekTo(seconds) {
	const v = video.value
	if (!v || !Number.isFinite(seconds)) {
		return
	}
	v.currentTime = Math.max(0, Math.min(seconds, duration.value || seconds))
}

function seekBy(delta) {
	seekTo((video.value?.currentTime ?? 0) + delta)
}

function onSeeking() {
	currentTime.value = video.value.currentTime
	// Report the target straight away so the server lets the player fetch there.
	heartbeat.beat()
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
	currentTime.value = video.value.currentTime
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
}

// --- Full screen, idle UI, keyboard ------------------------------------------

function toggleFullscreen() {
	if (fullscreen.value) {
		exitFullscreen()
	} else if (container.value.requestFullscreen) {
		// The whole player, so the watermark and controls stay visible.
		container.value.requestFullscreen().catch(() => {})
	} else if (video.value.webkitEnterFullscreen) {
		// iPhone: only the video element can go full screen.
		video.value.webkitEnterFullscreen()
	}
}

function exitFullscreen() {
	if (document.fullscreenElement) {
		document.exitFullscreen().catch(() => {})
	}
}

function onFullscreenChange() {
	fullscreen.value = document.fullscreenElement === container.value
}

function wake() {
	idle.value = false
	window.clearTimeout(idleTimer)
	idleTimer = window.setTimeout(() => {
		idle.value = true
	}, 2500)
}

function onKey(event) {
	if (event.target instanceof HTMLSelectElement || stopReason.value) {
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

onMounted(() => {
	heartbeat = new Heartbeat(props.playback, playerState, stop)
	heartbeat.start()

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
	wake()
})

onBeforeUnmount(() => {
	heartbeat?.stop()
	hls?.destroy()
	window.clearInterval(watermarkTimer)
	window.clearTimeout(idleTimer)
	document.removeEventListener('fullscreenchange', onFullscreenChange)
})
</script>

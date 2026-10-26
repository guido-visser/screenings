<template>
	<div ref="root"
		class="review-player"
		:class="{ 'review-player--fullscreen': fullscreen }"
		tabindex="0"
		@keydown="onKey">
		<video ref="video"
			class="review-player__video"
			playsinline
			preload="metadata"
			@click="togglePlay"
			@dblclick="toggleFullscreen"
			@play="playing = true"
			@pause="playing = false"
			@ended="playing = false"
			@timeupdate="onTimeUpdate"
			@durationchange="onDurationChange"
			@loadedmetadata="onLoadedMetadata"
			@volumechange="onVolumeChange"
			@error="onError">
			<track v-for="subtitle in config.subtitles"
				:id="'review-subtitle-' + subtitle.id"
				:key="subtitle.id"
				kind="subtitles"
				:src="subtitle.src"
				:srclang="subtitle.lang"
				:label="subtitle.label">
		</video>

		<p v-if="error" class="review-player__error" role="alert">
			{{ error }}
		</p>

		<!-- The selected note, over the video: readable in fullscreen too. -->
		<article v-if="selected"
			class="review-player__card"
			:style="{ '--viewer-colour': selected.colour }"
			aria-live="polite">
			<header class="review-player__card-header">
				<span class="review-player__dot" aria-hidden="true" />
				<strong class="review-player__card-name">{{ selected.name }}</strong>
				<span class="review-player__card-time">{{ timecode(selected.position) }}</span>
				<button class="review-player__card-close" :aria-label="t('screenings', 'Close note')" @click="emit('close')">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiClose" /></svg>
				</button>
			</header>
			<p class="review-player__card-body">
				{{ selected.body }}
			</p>
		</article>

		<div class="review-player__controls" @click.stop>
			<div ref="track"
				class="review-player__track"
				role="slider"
				tabindex="0"
				:aria-label="t('screenings', 'Position')"
				:aria-valuemin="0"
				:aria-valuemax="Math.round(duration)"
				:aria-valuenow="Math.round(currentTime)"
				:aria-valuetext="timecode(currentTime)"
				@pointerdown="onTrackDown">
				<div class="review-player__rail">
					<div class="review-player__progress" :style="{ width: percent(currentTime) + '%' }" />
				</div>
				<div class="review-player__thumb" :style="{ left: percent(currentTime) + '%' }" />

				<button v-for="group in groups"
					:key="group.key"
					class="review-player__marker"
					:class="{
						'review-player__marker--group': group.notes.length > 1,
						'review-player__marker--active': selected && group.notes.some((n) => n.id === selected.id),
					}"
					:style="{ left: percent(group.position) + '%', '--viewer-colour': group.notes[0].colour }"
					:aria-label="markerLabel(group)"
					@pointerdown.stop
					@click.stop="onMarker(group)"
					@mouseenter="hovered = group"
					@mouseleave="hovered = null"
					@focus="hovered = group"
					@blur="hovered = null">
					<template v-if="group.notes.length > 1">
						{{ group.notes.length }}
					</template>
				</button>

				<!-- Hover: who and what, without clicking. -->
				<div v-if="hovered && openGroup !== hovered"
					class="review-player__tooltip"
					:style="{ left: clampPercent(percent(hovered.position)) + '%' }"
					aria-hidden="true">
					<div v-for="note in hovered.notes.slice(0, 3)" :key="note.id" class="review-player__tooltip-line">
						<span class="review-player__dot" :style="{ '--viewer-colour': note.colour }" />
						<strong>{{ note.name }}</strong> · {{ timecode(note.position) }}
						<span class="review-player__tooltip-text">{{ firstLine(note.body) }}</span>
					</div>
					<div v-if="hovered.notes.length > 3" class="review-player__tooltip-more">
						{{ n('screenings', 'and {count} more', 'and {count} more', hovered.notes.length - 3, { count: hovered.notes.length - 3 }) }}
					</div>
				</div>

				<!-- Notes close together: pick one. -->
				<div v-if="openGroup"
					class="review-player__group"
					:style="{ left: clampPercent(percent(openGroup.position)) + '%' }"
					role="menu"
					@pointerdown.stop>
					<button v-for="note in openGroup.notes"
						:key="note.id"
						role="menuitem"
						class="review-player__group-item"
						@click.stop="pick(note)">
						<span class="review-player__dot" :style="{ '--viewer-colour': note.colour }" />
						<span class="review-player__group-time">{{ timecode(note.position) }}</span>
						<strong>{{ note.name }}</strong>
						<span class="review-player__tooltip-text">{{ firstLine(note.body) }}</span>
					</button>
				</div>
			</div>

			<div class="review-player__bar">
				<button class="review-player__button" :aria-label="playing ? t('screenings', 'Pause') : t('screenings', 'Play')" @click="togglePlay">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="playing ? mdiPause : mdiPlay" /></svg>
				</button>
				<button class="review-player__button review-player__button--skip" :aria-label="t('screenings', 'Back 10 seconds')" @click="seekBy(-10)">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiRewind10" /></svg>
				</button>
				<button class="review-player__button review-player__button--skip" :aria-label="t('screenings', 'Forward 10 seconds')" @click="seekBy(10)">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiFastForward10" /></svg>
				</button>
				<button class="review-player__button review-player__button--mute" :aria-label="muted ? t('screenings', 'Unmute') : t('screenings', 'Mute')" @click="toggleMute">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="muted ? mdiVolumeOff : mdiVolumeHigh" /></svg>
				</button>
				<span class="review-player__time">{{ timecode(currentTime) }} / {{ timecode(duration) }}</span>
				<span class="review-player__spacer" />
				<label v-if="config.subtitles.length" class="review-player__select">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiSubtitlesOutline" /></svg>
					<select v-model="subtitle" :aria-label="t('screenings', 'Subtitles')" @change="applySubtitle">
						<option value="">{{ t('screenings', 'Off') }}</option>
						<option v-for="s in config.subtitles" :key="s.id" :value="String(s.id)">
							{{ s.label }}
						</option>
					</select>
				</label>
				<button class="review-player__button" :aria-label="fullscreen ? t('screenings', 'Exit full screen') : t('screenings', 'Full screen')" @click="toggleFullscreen">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="fullscreen ? mdiFullscreenExit : mdiFullscreen" /></svg>
				</button>
			</div>
		</div>
	</div>
</template>

<script setup>
import {
	mdiClose,
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
import { n, t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { formatDuration } from '../format.js'

const props = defineProps({
	/** From api.preview(): mode, src, durationS, subtitles. */
	config: { type: Object, required: true },
	/** Notes to mark: { id, position, body, name, colour }, in film order. */
	notes: { type: Array, default: () => [] },
	/** The note shown as a card over the video, or null. */
	selected: { type: Object, default: null },
})
const emit = defineEmits(['select', 'close', 'time'])

/** Like the viewer's player: whole seconds, never rounded up past the moment. */
const timecode = (seconds) => formatDuration(Math.floor(seconds))

/** Markers closer than this share one bubble. */
const GROUP_FRACTION = 0.015

const root = ref(null)
const video = ref(null)
const track = ref(null)
const playing = ref(false)
const currentTime = ref(0)
const duration = ref(props.config.durationS || 0)
const muted = ref(false)
const fullscreen = ref(false)
const subtitle = ref(String(props.config.subtitles.find((s) => s.isDefault)?.id ?? ''))
const error = ref(null)
const hovered = ref(null)
const openGroup = ref(null)
let hls = null
// A jump asked for before the film's metadata arrived (a link to a note).
let pendingSeek = null

const groups = computed(() => {
	const span = Math.max(1, duration.value) * GROUP_FRACTION
	const result = []
	for (const note of props.notes) {
		const last = result[result.length - 1]
		if (last && note.position - last.position <= span) {
			last.notes.push(note)
		} else {
			result.push({ key: 'g' + note.id, position: note.position, notes: [note] })
		}
	}
	return result
})

function percent(seconds) {
	return duration.value > 0 ? Math.min(100, Math.max(0, (seconds / duration.value) * 100)) : 0
}

/** Keeps popups that sit centred on a marker inside the player. */
function clampPercent(value) {
	return Math.min(85, Math.max(15, value))
}

function firstLine(body) {
	const line = body.split('\n')[0]
	return line.length > 80 ? line.slice(0, 79) + '…' : line
}

function markerLabel(group) {
	if (group.notes.length === 1) {
		const note = group.notes[0]
		return t('screenings', 'Note by {name} at {time}', { name: note.name, time: timecode(note.position) })
	}
	return n('screenings', '{count} note near {time}', '{count} notes near {time}', group.notes.length, { count: group.notes.length, time: timecode(group.position) })
}

function onMarker(group) {
	if (group.notes.length === 1) {
		pick(group.notes[0])
	} else {
		openGroup.value = openGroup.value === group ? null : group
	}
}

function pick(note) {
	openGroup.value = null
	emit('select', note)
}

// --- Playback ---------------------------------------------------------------

async function setup() {
	const v = video.value
	if (props.config.mode !== 'hls') {
		v.src = props.config.src
		return
	}
	const { default: Hls } = await import('hls.js')
	if (Hls.isSupported()) {
		hls = new Hls({ maxBufferLength: 30, backBufferLength: 60 })
		hls.on(Hls.Events.ERROR, (_, data) => {
			if (data.fatal) {
				error.value = t('screenings', 'The film could not be loaded. Reload the page to try again.')
			}
		})
		hls.loadSource(props.config.src)
		hls.attachMedia(v)
	} else if (v.canPlayType('application/vnd.apple.mpegurl')) {
		// Safari: native HLS sends the login cookie by itself.
		v.src = props.config.src
	} else {
		error.value = t('screenings', 'This browser can\'t play the film.')
	}
}

/**
 * Jumps to a moment; plays from there or stays paused.
 *
 * @param {number} seconds where to go
 * @param {boolean} play whether to start playing
 */
function seekTo(seconds, play = false) {
	const v = video.value
	if (!v || !Number.isFinite(seconds)) {
		return
	}
	if (v.readyState < 1) {
		pendingSeek = { seconds, play }
		currentTime.value = seconds
		return
	}
	v.currentTime = Math.max(0, Math.min(seconds, duration.value || seconds))
	currentTime.value = v.currentTime
	if (play) {
		v.play().catch(() => {})
	} else {
		v.pause()
	}
}

function seekBy(delta) {
	seekTo((video.value?.currentTime ?? 0) + delta, playing.value)
}

function togglePlay() {
	const v = video.value
	if (!v) {
		return
	}
	if (v.paused || v.ended) {
		v.play().catch(() => {})
	} else {
		v.pause()
	}
}

function toggleMute() {
	video.value.muted = !video.value.muted
}

function onVolumeChange() {
	muted.value = video.value.muted || video.value.volume === 0
}

function onTimeUpdate() {
	currentTime.value = video.value.currentTime
	emit('time', currentTime.value)
}

function onDurationChange() {
	if (Number.isFinite(video.value.duration) && video.value.duration > 0) {
		duration.value = video.value.duration
	}
}

function onLoadedMetadata() {
	onDurationChange()
	if (pendingSeek) {
		const { seconds, play } = pendingSeek
		pendingSeek = null
		seekTo(seconds, play)
	}
}

function onError() {
	if (!hls) {
		error.value = t('screenings', 'The film could not be loaded. Reload the page to try again.')
	}
}

function applySubtitle() {
	for (const textTrack of video.value?.textTracks ?? []) {
		textTrack.mode = textTrack.id === 'review-subtitle-' + subtitle.value ? 'showing' : 'disabled'
	}
}

// --- Seeking on the timeline ----------------------------------------------

function timeAt(clientX) {
	const box = track.value.getBoundingClientRect()
	return Math.min(1, Math.max(0, (clientX - box.left) / box.width)) * duration.value
}

function onTrackDown(event) {
	openGroup.value = null
	const wasPlaying = playing.value
	const move = (e) => seekTo(timeAt(e.clientX), false)
	const up = (e) => {
		seekTo(timeAt(e.clientX), wasPlaying)
		window.removeEventListener('pointermove', move)
		window.removeEventListener('pointerup', up)
	}
	move(event)
	window.addEventListener('pointermove', move)
	window.addEventListener('pointerup', up)
}

// --- Fullscreen and keys ----------------------------------------------------

function toggleFullscreen() {
	if (document.fullscreenElement) {
		document.exitFullscreen().catch(() => {})
	} else {
		root.value?.requestFullscreen?.().catch(() => {})
	}
}

function onFullscreenChange() {
	fullscreen.value = document.fullscreenElement === root.value
}

function onKey(event) {
	if (event.target instanceof HTMLSelectElement) {
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
		Escape: () => { openGroup.value = null },
	}
	const action = actions[event.key]
	if (action && !(event.target instanceof HTMLButtonElement && event.key === ' ')) {
		event.preventDefault()
		action()
	}
}

defineExpose({ seekTo, pause: () => video.value?.pause(), focus: () => root.value?.focus() })

onMounted(() => {
	setup()
	video.value.textTracks.addEventListener('addtrack', applySubtitle)
	applySubtitle()
	document.addEventListener('fullscreenchange', onFullscreenChange)
})

onBeforeUnmount(() => {
	hls?.destroy()
	document.removeEventListener('fullscreenchange', onFullscreenChange)
})
</script>

<style scoped>
/* A dark screen in either theme, like a real player. */
.review-player {
	container: review-player / inline-size;
	position: relative;
	aspect-ratio: 16 / 9;
	background: #000;
	border-radius: var(--border-radius-large, 12px);
	overflow: hidden;
	outline: none;
	color: #fff;
}

.review-player:focus-visible {
	box-shadow: 0 0 0 2px var(--color-primary-element);
}

.review-player--fullscreen {
	aspect-ratio: auto;
	width: 100vw;
	height: 100vh;
	border-radius: 0;
}

.review-player__video {
	display: block;
	width: 100%;
	height: 100%;
	background: #000;
}

.review-player__error {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0;
	padding: 24px;
	text-align: center;
	background: rgba(0, 0, 0, 0.75);
}

.review-player__dot {
	display: inline-block;
	flex: none;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--viewer-colour, #fff);
}

.review-player__card {
	position: absolute;
	z-index: 3;
	top: 12px;
	left: 12px;
	width: min(420px, calc(100% - 24px));
	max-height: calc(100% - 120px);
	display: flex;
	flex-direction: column;
	box-sizing: border-box;
	padding: 12px 14px;
	background: rgba(16, 16, 18, 0.92);
	border-left: 4px solid var(--viewer-colour);
	border-radius: 8px;
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
}

.review-player__card-header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.review-player__card-name {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.review-player__card-time {
	color: rgba(255, 255, 255, 0.7);
	font-variant-numeric: tabular-nums;
}

.review-player .review-player__card-close {
	width: 32px;
	height: 32px;
	min-height: 0;
	margin: 0 -6px 0 auto;
	padding: 6px;
	color: #fff;
	background: transparent;
	border: 0;
	border-radius: 50%;
	cursor: pointer;
}

.review-player .review-player__card-close:hover {
	background: rgba(255, 255, 255, 0.15);
}

.review-player__card-close svg,
.review-player__button svg,
.review-player__select svg {
	display: block;
	width: 100%;
	height: 100%;
	fill: currentColor;
}

.review-player__card-body {
	margin: 8px 0 0;
	overflow-y: auto;
	white-space: pre-line;
	overflow-wrap: anywhere;
	line-height: 1.45;
}

.review-player__controls {
	position: absolute;
	z-index: 2;
	left: 0;
	right: 0;
	bottom: 0;
	padding: 28px 12px 6px;
	background: linear-gradient(transparent, rgba(0, 0, 0, 0.85));
}

.review-player__track {
	position: relative;
	height: 24px;
	margin: 0 7px;
	cursor: pointer;
	touch-action: none;
	outline: none;
}

.review-player__track:focus-visible .review-player__rail {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 4px;
}

.review-player__rail {
	position: absolute;
	left: 0;
	right: 0;
	top: 50%;
	height: 4px;
	transform: translateY(-50%);
	background: rgba(255, 255, 255, 0.25);
	border-radius: 2px;
}

.review-player__progress {
	height: 100%;
	background: rgba(255, 255, 255, 0.85);
	border-radius: 2px;
}

.review-player__thumb {
	position: absolute;
	top: 50%;
	width: 12px;
	height: 12px;
	transform: translate(-50%, -50%);
	background: #fff;
	border-radius: 50%;
	pointer-events: none;
}

.review-player .review-player__marker {
	position: absolute;
	z-index: 1;
	top: 50%;
	width: 14px;
	height: 14px;
	min-width: 0;
	min-height: 0;
	margin: 0;
	padding: 0;
	transform: translate(-50%, -50%);
	background: var(--viewer-colour);
	border: 2px solid #000;
	border-radius: 50%;
	box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.6);
	cursor: pointer;
	font-size: 11px;
	font-weight: 700;
	line-height: 1;
	color: #000;
}

.review-player .review-player__marker--group {
	width: auto;
	min-width: 20px;
	height: 20px;
	padding: 0 5px;
	background: #fff;
	border-radius: 10px;
}

.review-player .review-player__marker:hover,
.review-player .review-player__marker:focus-visible,
.review-player .review-player__marker--active {
	z-index: 2;
	box-shadow: 0 0 0 3px #fff;
	outline: none;
}

.review-player__tooltip,
.review-player__group {
	position: absolute;
	z-index: 3;
	bottom: calc(100% + 8px);
	width: max-content;
	max-width: min(360px, 70cqi);
	transform: translateX(-50%);
	padding: 8px 10px;
	background: rgba(16, 16, 18, 0.95);
	border: 1px solid rgba(255, 255, 255, 0.15);
	border-radius: 8px;
	font-size: 0.9em;
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
}

.review-player__tooltip {
	pointer-events: none;
}

.review-player__tooltip-line {
	display: flex;
	align-items: baseline;
	flex-wrap: wrap;
	gap: 0 6px;
}

.review-player__tooltip-line + .review-player__tooltip-line {
	margin-top: 6px;
}

.review-player__tooltip-text {
	flex-basis: 100%;
	color: rgba(255, 255, 255, 0.75);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.review-player__tooltip-more {
	margin-top: 6px;
	color: rgba(255, 255, 255, 0.6);
}

.review-player__group {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 4px;
	max-height: 240px;
	overflow-y: auto;
}

.review-player .review-player__group-item {
	display: flex;
	align-items: baseline;
	flex-wrap: wrap;
	gap: 0 6px;
	width: 100%;
	min-height: 0;
	margin: 0;
	padding: 6px 8px;
	text-align: start;
	font: inherit;
	color: #fff;
	background: transparent;
	border: 0;
	border-radius: 6px;
	cursor: pointer;
}

.review-player .review-player__group-item:hover,
.review-player .review-player__group-item:focus-visible {
	background: rgba(255, 255, 255, 0.12);
}

.review-player__group-time {
	font-variant-numeric: tabular-nums;
	color: rgba(255, 255, 255, 0.7);
}

.review-player__bar {
	display: flex;
	align-items: center;
	gap: 4px;
	margin-top: 2px;
}

.review-player .review-player__button {
	flex: none;
	width: 40px;
	height: 40px;
	min-height: 0;
	margin: 0;
	padding: 8px;
	color: #fff;
	background: transparent;
	border: 0;
	border-radius: 8px;
	cursor: pointer;
}

.review-player .review-player__button:hover,
.review-player__select:hover {
	background: rgba(255, 255, 255, 0.15);
}

.review-player__time {
	margin-left: 6px;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.review-player__spacer {
	flex: 1;
}

.review-player__select {
	display: flex;
	align-items: center;
	gap: 4px;
	height: 40px;
	padding: 0 6px;
	border-radius: 8px;
}

.review-player__select svg {
	width: 22px;
	height: 22px;
}

.review-player .review-player__select select {
	min-height: 0;
	max-width: 140px;
	margin: 0;
	padding: 2px 4px;
	color: #fff;
	background: transparent;
	border: 0;
	font: inherit;
}

.review-player__select option {
	color: #000;
}

@container review-player (max-width: 520px) {
	.review-player .review-player__button--skip,
	.review-player .review-player__button--mute,
	.review-player__select svg {
		display: none;
	}

	.review-player__time {
		margin-left: 2px;
		font-size: 0.9em;
	}

	.review-player .review-player__button {
		width: 36px;
		height: 36px;
		padding: 6px;
	}

	.review-player__card {
		max-height: calc(100% - 96px);
		padding: 8px 10px;
		font-size: 0.9em;
	}
}
</style>

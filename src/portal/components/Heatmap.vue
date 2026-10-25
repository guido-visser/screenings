<template>
	<figure class="heatmap" :class="{ 'heatmap--dark': dark }">
		<div class="heatmap__strip"
			role="img"
			:aria-label="summary"
			@mouseleave="hover = null">
			<div v-for="(count, index) in buckets"
				:key="index"
				class="heatmap__cell"
				:style="{ background: color(count) }"
				@mouseenter="hover = index" />
			<div v-if="hover !== null"
				class="heatmap__tooltip"
				:style="{ left: tooltipLeft }"
				role="status">
				<strong>{{ formatDuration(hover * bucketSeconds) }}–{{ formatDuration(Math.min(durationS, (hover + 1) * bucketSeconds)) }}</strong>
				<span>{{ countText(buckets[hover]) }}</span>
			</div>
		</div>
		<div class="heatmap__axis" aria-hidden="true">
			<span v-for="tick in ticks" :key="tick" :style="{ left: (tick / durationS) * 100 + '%' }">
				{{ formatDuration(tick) }}
			</span>
		</div>
		<figcaption class="heatmap__legend">
			<span>{{ t('screenings', 'Not watched') }}</span>
			<span class="heatmap__swatch" :style="{ background: zeroColor }" />
			<span class="heatmap__ramp">
				<span v-for="step in ramp" :key="step" :style="{ background: step }" />
			</span>
			<span>{{ moreLabel }}</span>
		</figcaption>
	</figure>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { formatDuration } from '../format.js'

const props = defineProps({
	/** Count per time bucket. */
	buckets: { type: Array, required: true },
	bucketSeconds: { type: Number, required: true },
	/** The count that means "everyone" (darkest). */
	max: { type: Number, required: true },
	durationS: { type: Number, required: true },
	/** (count, max) => tooltip text, e.g. "3 of 5 viewers". */
	countLabel: { type: Function, required: true },
	moreLabel: { type: String, required: true },
	summary: { type: String, required: true },
})

// One-hue sequential ramp (blue), light→dark for "more". Dark mode uses its
// own steps: low values recede toward the dark surface, high ones get bright.
const RAMP_LIGHT = ['#b7d3f6', '#86b6ef', '#5598e7', '#2a78d6', '#1c5cab', '#104281']
const RAMP_DARK = ['#184f95', '#1c5cab', '#256abf', '#3987e5', '#6da7ec', '#9ec5f4']

const hover = ref(null)
const dark = ref(false)

const ramp = computed(() => dark.value ? RAMP_DARK : RAMP_LIGHT)
const zeroColor = 'var(--color-background-darker)'

function color(count) {
	if (!count || props.max <= 0) {
		return zeroColor
	}
	const index = Math.min(ramp.value.length - 1, Math.ceil((count / props.max) * ramp.value.length) - 1)
	return ramp.value[Math.max(0, index)]
}

function countText(count) {
	return props.countLabel(count ?? 0, props.max)
}

const tooltipLeft = computed(() => {
	const share = (hover.value + 0.5) / props.buckets.length
	return `clamp(80px, ${share * 100}%, calc(100% - 80px))`
})

const ticks = computed(() => {
	if (props.durationS <= 0) {
		return []
	}
	return [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(f * props.durationS))
})

// Nextcloud's theme can be dark without the OS being dark, so read the
// actual background instead of relying on prefers-color-scheme.
function detectDark() {
	const bg = getComputedStyle(document.body).getPropertyValue('--color-main-background').trim()
	const hex = bg.match(/^#([0-9a-f]{6})$/i)?.[1]
	if (!hex) {
		dark.value = window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
		return
	}
	const [r, g, b] = [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
	dark.value = 0.2126 * r + 0.7152 * g + 0.0722 * b < 0.5
}

let themeObserver = null
onMounted(() => {
	detectDark()
	themeObserver = new MutationObserver(detectDark)
	themeObserver.observe(document.body, { attributes: true, attributeFilter: ['data-themes', 'class', 'style'] })
})
onBeforeUnmount(() => themeObserver?.disconnect())
</script>

<style scoped>
.heatmap {
	margin: 0;
}

.heatmap__strip {
	position: relative;
	display: flex;
	gap: 1px;
	height: 32px;
}

.heatmap__cell {
	flex: 1;
	min-width: 1px;
}

.heatmap__cell:first-child {
	border-radius: 4px 0 0 4px;
}

.heatmap__cell:last-of-type {
	border-radius: 0 4px 4px 0;
}

.heatmap__cell:hover {
	outline: 2px solid var(--color-main-text);
	outline-offset: 1px;
	position: relative;
	z-index: 1;
}

.heatmap__tooltip {
	position: absolute;
	bottom: calc(100% + 8px);
	transform: translateX(-50%);
	display: flex;
	align-items: baseline;
	gap: 8px;
	padding: 4px 10px;
	white-space: nowrap;
	font-size: 0.9em;
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 2px 8px var(--color-box-shadow);
	pointer-events: none;
	z-index: 2;
}

.heatmap__axis {
	position: relative;
	height: 20px;
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
	font-variant-numeric: tabular-nums;
}

.heatmap__axis span {
	position: absolute;
	transform: translateX(-50%);
}

.heatmap__axis span:first-child {
	transform: none;
}

.heatmap__axis span:last-child {
	transform: translateX(-100%);
}

.heatmap__legend {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.heatmap__swatch {
	width: 14px;
	height: 10px;
	border-radius: 2px;
	margin-right: 8px;
}

.heatmap__ramp {
	display: flex;
	gap: 1px;
}

.heatmap__ramp span {
	width: 14px;
	height: 10px;
}

.heatmap__ramp span:first-child {
	border-radius: 2px 0 0 2px;
}

.heatmap__ramp span:last-child {
	border-radius: 0 2px 2px 0;
}
</style>

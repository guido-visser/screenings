<template>
	<span class="live-badge" :class="{ 'live-badge--paused': !live.playing }">
		<span class="live-badge__dot" aria-hidden="true" />
		{{ live.playing
			? t('screenings', 'Watching at {position}', { position: formatDuration(live.positionS) })
			: t('screenings', 'Paused at {position}', { position: formatDuration(live.positionS) }) }}
		<span v-if="live.subtitle" class="live-badge__extra">· {{ t('screenings', 'subtitles {lang}', { lang: live.subtitle }) }}</span>
	</span>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import { formatDuration } from '../format.js'

defineProps({
	live: { type: Object, required: true },
})
</script>

<style scoped>
.live-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-weight: 600;
	color: var(--color-success-text);
	white-space: nowrap;
}

.live-badge--paused {
	color: var(--color-warning-text);
}

.live-badge__dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: currentColor;
	animation: live-pulse 1.6s ease-in-out infinite;
}

.live-badge--paused .live-badge__dot {
	animation: none;
}

.live-badge__extra {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

@keyframes live-pulse {
	50% {
		opacity: 0.3;
	}
}

@media (prefers-reduced-motion: reduce) {
	.live-badge__dot {
		animation: none;
	}
}
</style>

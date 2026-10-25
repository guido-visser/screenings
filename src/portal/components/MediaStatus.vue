<template>
	<div class="media-status">
		<p class="media-status__line">
			{{ t('screenings', 'Media status:') }} <strong>{{ statusLabel }}</strong>
			<span v-if="screening.durationMs && screening.mediaStatus === 'ready'">
				· {{ t('screenings', 'Duration {duration}', { duration: formatDuration(screening.durationMs) }) }}
			</span>
		</p>

		<template v-if="screening.mediaStatus === 'pending' || screening.mediaStatus === 'processing'">
			<NcProgressBar :value="screening.mediaProgress" size="medium" />
			<p class="media-status__hint">
				{{ screening.mediaStatus === 'pending'
					? t('screenings', 'Processing starts in the background, usually within a few minutes. You can leave this page.')
					: t('screenings', 'You can leave this page; processing continues on the server.') }}
			</p>
		</template>

		<NcNoteCard v-if="screening.mediaStatus === 'error'" type="error">
			<p>{{ screening.mediaError || t('screenings', 'Processing failed.') }}</p>
		</NcNoteCard>

		<NcNoteCard v-if="warnings.length" type="warning">
			<p v-for="warning in warnings" :key="warning">
				{{ warning }}
			</p>
		</NcNoteCard>

		<NcButton v-if="canReprocess" :disabled="busy" @click="emit('reprocess')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiRefresh" />
			</template>
			{{ screening.mediaStatus === 'error' ? t('screenings', 'Try again') : t('screenings', 'Process again') }}
		</NcButton>
	</div>
</template>

<script setup>
import { mdiRefresh } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import { computed } from 'vue'

const props = defineProps({
	screening: { type: Object, required: true },
	busy: { type: Boolean, default: false },
})
const emit = defineEmits(['reprocess'])

const statusLabels = {
	none: t('screenings', 'No video selected'),
	pending: t('screenings', 'Waiting to be processed'),
	processing: t('screenings', 'Processing'),
	ready: t('screenings', 'Ready'),
	error: t('screenings', 'Error'),
}

const statusLabel = computed(() => {
	const s = props.screening
	if (s.mediaStatus === 'processing') {
		return `${statusLabels.processing} (${s.mediaProgress}%)`
	}
	return statusLabels[s.mediaStatus] ?? s.mediaStatus
})

const warnings = computed(() => (props.screening.mediaWarning ?? '').split('\n').filter(Boolean))

// Re-running is useful after an error, or after replacing the source file's
// contents (same file, new export). Direct MP4 re-checks the file instantly.
const canReprocess = computed(() => props.screening.sourceFileId !== null
	&& ['ready', 'error'].includes(props.screening.mediaStatus))

function formatDuration(ms) {
	const total = Math.round(ms / 1000)
	const h = Math.floor(total / 3600)
	const m = Math.floor((total % 3600) / 60)
	const s = String(total % 60).padStart(2, '0')
	return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`
}
</script>

<style scoped>
.media-status {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
}

.media-status > * {
	max-width: 100%;
}

.media-status :deep(.progress-bar) {
	width: 100%;
}

.media-status__line,
.media-status__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>

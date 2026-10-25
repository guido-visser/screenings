<template>
	<NcNoteCard type="info" class="hls-help">
		<p>{{ t('screenings', 'Convert the film to HLS on your own computer with ffmpeg, upload the whole output folder to Files, then choose its master.m3u8 here. Use MPEG-TS segments and don\'t encrypt them; the server encrypts them.') }}</p>
		<pre>{{ command }}</pre>
		<NcButton variant="tertiary" @click="copy">
			<template #icon>
				<NcIconSvgWrapper :path="mdiContentCopy" />
			</template>
			{{ t('screenings', 'Copy command') }}
		</NcButton>
	</NcNoteCard>
</template>

<script setup>
import { mdiContentCopy } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

// Kept in sync with docs/prepare-hls.md
const command = 'ffmpeg -i film.mov -c:v libx264 -preset slow -crf 20 -pix_fmt yuv420p -g 96 -keyint_min 96 -sc_threshold 0 -c:a aac -b:a 160k -ac 2 -f hls -hls_time 4 -hls_playlist_type vod -hls_segment_type mpegts -hls_segment_filename "seg_%05d.ts" master.m3u8'

async function copy() {
	await navigator.clipboard.writeText(command)
	showSuccess(t('screenings', 'Command copied'))
}
</script>

<style scoped>
.hls-help pre {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	font-size: 0.85em;
	margin: 8px 0 0;
	padding: 8px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}
</style>

<template>
	<NcSettingsSection :name="t('screenings', 'Screenings: video processing')"
		:description="t('screenings', 'Direct MP4 and Pre-packaged HLS work on every server. Server transcode converts videos to encrypted HLS on this server with ffmpeg, which needs CPU time and temporary disk space about the size of the film.')">
		<NcNoteCard v-if="ffmpegFound" type="success">
			<p>{{ t('screenings', 'ffmpeg found: {path}', { path: settings.detected.ffmpeg.path }) }}</p>
			<p class="admin__version">
				{{ settings.detected.ffmpeg.version }}
			</p>
			<p v-if="!settings.detected.ffprobe">
				{{ t('screenings', 'ffprobe was not found. It usually ships with ffmpeg.') }}
			</p>
		</NcNoteCard>
		<NcNoteCard v-else type="info">
			{{ t('screenings', 'ffmpeg was not found on this server, so Server transcode is unavailable. Install ffmpeg or set its path below.') }}
		</NcNoteCard>

		<NcCheckboxRadioSwitch :model-value="settings.transcodeEnabled"
			type="switch"
			:disabled="saving || (!settings.transcodeEnabled && !transcodeAvailable)"
			@update:model-value="(value) => save({ transcodeEnabled: value })">
			{{ t('screenings', 'Allow Server transcode') }}
		</NcCheckboxRadioSwitch>

		<fieldset class="admin__modes" :disabled="!settings.transcodeEnabled || saving">
			<legend>{{ t('screenings', 'Transcoding') }}</legend>
			<NcCheckboxRadioSwitch :model-value="settings.transcodeMode"
				type="radio"
				name="transcodeMode"
				value="full"
				:description="t('screenings', 'Re-encodes to H.264 in up to three qualities (1080p, 720p, 480p). Best playback; a film takes roughly as long as its own runtime on a typical server.')"
				@update:model-value="(value) => save({ transcodeMode: value })">
				{{ t('screenings', 'Full (several qualities)') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch :model-value="settings.transcodeMode"
				type="radio"
				name="transcodeMode"
				value="remux"
				:description="t('screenings', 'Only repackages H.264 videos into encrypted HLS, without re-encoding. One quality, takes seconds to minutes, almost no CPU.')"
				@update:model-value="(value) => save({ transcodeMode: value })">
				{{ t('screenings', 'Remux only (low-power servers)') }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<form class="admin__paths" @submit.prevent="save({ ffmpegPath: paths.ffmpeg, ffprobePath: paths.ffprobe })">
			<NcTextField v-model="paths.ffmpeg"
				:label="t('screenings', 'ffmpeg path')"
				:placeholder="t('screenings', 'Detect automatically')" />
			<NcTextField v-model="paths.ffprobe"
				:label="t('screenings', 'ffprobe path')"
				:placeholder="t('screenings', 'Detect automatically')" />
			<NcButton type="submit" :disabled="saving || !pathsChanged">
				{{ t('screenings', 'Save paths') }}
			</NcButton>
		</form>
	</NcSettingsSection>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, reactive, ref } from 'vue'

const settings = ref(loadState('screenings', 'adminSettings'))
const paths = reactive({ ffmpeg: settings.value.ffmpegPath, ffprobe: settings.value.ffprobePath })
const saving = ref(false)

const ffmpegFound = computed(() => settings.value.detected.ffmpeg !== null)
const transcodeAvailable = computed(() => ffmpegFound.value && settings.value.detected.ffprobe !== null)
const pathsChanged = computed(() => paths.ffmpeg !== settings.value.ffmpegPath || paths.ffprobe !== settings.value.ffprobePath)

async function save(changes) {
	saving.value = true
	try {
		const { data } = await axios.put(generateUrl('/apps/screenings/api/admin/settings'), changes)
		settings.value = data
		paths.ffmpeg = data.ffmpegPath
		paths.ffprobe = data.ffprobePath
		showSuccess(t('screenings', 'Settings saved'))
	} catch (error) {
		showError(error?.response?.data?.message ?? t('screenings', 'Could not save the settings'))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.admin__version {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	overflow-wrap: anywhere;
}

.admin__modes {
	margin: 16px 0;
}

.admin__modes legend {
	font-weight: bold;
	margin-bottom: 4px;
}

.admin__modes:disabled {
	opacity: 0.6;
}

.admin__paths {
	display: grid;
	grid-template-columns: 1fr 1fr auto;
	align-items: end;
	gap: 8px;
	max-width: 800px;
}

@media (max-width: 700px) {
	.admin__paths {
		grid-template-columns: 1fr;
	}
}
</style>

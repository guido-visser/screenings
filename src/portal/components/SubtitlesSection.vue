<template>
	<section class="subtitles">
		<h3>{{ t('screenings', 'Subtitles') }}</h3>

		<ul v-if="items.length" class="subtitles__list">
			<li v-for="subtitle in items" :key="subtitle.id" class="subtitles__item">
				<span class="subtitles__lang">{{ subtitle.lang }}</span>
				<span class="subtitles__label">{{ subtitle.label }}</span>
				<NcCheckboxRadioSwitch :model-value="subtitle.isDefault"
					type="switch"
					@update:model-value="(value) => setDefault(subtitle, value)">
					{{ t('screenings', 'On by default') }}
				</NcCheckboxRadioSwitch>
				<NcButton variant="tertiary" :aria-label="t('screenings', 'Delete subtitle')" @click="remove(subtitle)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDeleteOutline" />
					</template>
				</NcButton>
			</li>
		</ul>
		<p v-else class="subtitles__empty">
			{{ t('screenings', 'No subtitles yet.') }}
		</p>

		<div class="subtitles__import">
			<NcButton :disabled="!hasSource || importing" @click="importSiblings">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFolderSearchOutline" />
				</template>
				{{ t('screenings', 'Add subtitles from the video\'s folder') }}
			</NcButton>
			<p class="subtitles__hint">
				{{ t('screenings', 'Picks up .srt and .vtt files named like the video, such as "film.en.srt" or "film.nl.srt" next to "film.mp4". This also happens automatically when you choose a video.') }}
			</p>
		</div>

		<form class="subtitles__upload" @submit.prevent="upload">
			<label class="subtitles__file">
				<span>{{ t('screenings', 'Subtitle file (.srt or .vtt)') }}</span>
				<input ref="fileInput"
					type="file"
					accept=".srt,.vtt,text/vtt"
					@change="onFile">
			</label>
			<NcTextField v-model="lang" :label="t('screenings', 'Language code')" placeholder="en" />
			<NcTextField v-model="label" :label="t('screenings', 'Label')" :placeholder="t('screenings', 'English')" />
			<NcButton type="submit" :disabled="!file || !lang || uploading">
				<template #icon>
					<NcIconSvgWrapper :path="mdiUpload" />
				</template>
				{{ t('screenings', 'Upload') }}
			</NcButton>
		</form>
	</section>
</template>

<script setup>
import { mdiDeleteOutline, mdiFolderSearchOutline, mdiUpload } from '@mdi/js'
import { showError, showInfo, showSuccess, showWarning } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ref, watch } from 'vue'
import api, { errorMessage } from '../api.js'

const props = defineProps({
	screeningId: { type: Number, required: true },
	subtitles: { type: Array, default: () => [] },
	hasSource: { type: Boolean, default: false },
})

const items = ref([...props.subtitles])
// Choosing a new video can bring subtitles along (saved by the parent).
watch(() => props.subtitles, (subtitles) => {
	items.value = [...subtitles]
})
const importing = ref(false)

async function importSiblings() {
	importing.value = true
	try {
		const result = await api.importSubtitles(props.screeningId)
		items.value = result.subtitles
		if (result.added.length) {
			showSuccess(n('screenings', 'Added {count} subtitle', 'Added {count} subtitles', result.added.length, { count: result.added.length }))
		} else if (!result.skipped.length) {
			showInfo(t('screenings', 'No new subtitle files found next to the video.'))
		}
		for (const skipped of result.skipped) {
			showWarning(t('screenings', 'Skipped {file}: {reason}', { file: skipped.file, reason: skipped.reason }))
		}
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not add the subtitles')))
	} finally {
		importing.value = false
	}
}
const fileInput = ref(null)
const file = ref(null)
const lang = ref('')
const label = ref('')
const uploading = ref(false)

function onFile(event) {
	file.value = event.target.files?.[0] ?? null
}

async function upload() {
	uploading.value = true
	try {
		items.value.push(await api.uploadSubtitle(props.screeningId, file.value, lang.value, label.value))
		file.value = null
		lang.value = ''
		label.value = ''
		fileInput.value.value = ''
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not upload the subtitle')))
	} finally {
		uploading.value = false
	}
}

async function setDefault(subtitle, isDefault) {
	try {
		const saved = await api.updateSubtitle(subtitle.id, { isDefault })
		// Only one track can be the default.
		items.value = items.value.map((s) => s.id === saved.id ? saved : { ...s, isDefault: isDefault ? false : s.isDefault })
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not update the subtitle')))
	}
}

async function remove(subtitle) {
	try {
		await api.deleteSubtitle(subtitle.id)
		items.value = items.value.filter((s) => s.id !== subtitle.id)
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not delete the subtitle')))
	}
}
</script>

<style scoped>
.subtitles {
	margin-top: 32px;
}

.subtitles h3 {
	margin: 0 0 12px;
	font-size: 1.1em;
}

.subtitles__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.subtitles__item {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.subtitles__lang {
	min-width: 48px;
	font-family: monospace;
	text-transform: uppercase;
}

.subtitles__label {
	flex: 1;
	min-width: 120px;
}

.subtitles__import {
	margin: 12px 0;
}

.subtitles__hint {
	margin: 6px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.subtitles__empty {
	color: var(--color-text-maxcontrast);
}

.subtitles__upload {
	display: grid;
	grid-template-columns: 2fr 1fr 1fr auto;
	align-items: end;
	gap: 8px;
	margin-top: 12px;
}

.subtitles__file {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 700px) {
	.subtitles__upload {
		grid-template-columns: 1fr;
	}
}
</style>

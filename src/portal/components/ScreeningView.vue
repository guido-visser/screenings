<template>
	<div class="screening">
		<div v-if="!screening" class="screening__loading">
			<NcLoadingIcon :size="44" />
		</div>
		<template v-else>
			<header class="screening__header">
				<h2>{{ screening.title }}</h2>
				<NcActions>
					<NcActionButton @click="remove">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDeleteOutline" />
						</template>
						{{ t('screenings', 'Delete screening') }}
					</NcActionButton>
				</NcActions>
			</header>

			<form class="screening__form" @submit.prevent="save">
				<section class="screening__section">
					<h3>{{ t('screenings', 'General') }}</h3>
					<NcTextField v-model="form.title" :label="t('screenings', 'Title')" required />
					<NcTextArea v-model="form.description"
						:label="t('screenings', 'Description (shown to viewers)')"
						resize="vertical" />
				</section>

				<section class="screening__section">
					<h3>{{ t('screenings', 'Video') }}</h3>
					<div class="screening__modes" role="radiogroup" :aria-label="t('screenings', 'Delivery mode')">
						<NcCheckboxRadioSwitch v-for="mode in modes"
							:key="mode.id"
							v-model="form.deliveryMode"
							type="radio"
							name="deliveryMode"
							:value="mode.id"
							:description="mode.description">
							{{ mode.label }}
						</NcCheckboxRadioSwitch>
					</div>
					<div class="screening__source">
						<span class="screening__source-path">
							{{ form.sourcePath || t('screenings', 'No file selected') }}
						</span>
						<NcButton @click="pickSource">
							<template #icon>
								<NcIconSvgWrapper :path="mdiFolderOpenOutline" />
							</template>
							{{ form.deliveryMode === 'hls' ? t('screenings', 'Choose .m3u8 playlist') : t('screenings', 'Choose video') }}
						</NcButton>
					</div>
					<p class="screening__status">
						{{ t('screenings', 'Media status:') }} <strong>{{ statusLabel }}</strong>
					</p>
				</section>

				<section class="screening__section">
					<h3>{{ t('screenings', 'Access') }}</h3>
					<div class="screening__password">
						<NcPasswordField v-model="form.password"
							:label="screening.hasPassword ? t('screenings', 'New password (a password is set)') : t('screenings', 'Password (optional)')"
							autocomplete="new-password" />
						<NcButton v-if="screening.hasPassword" @click="removePassword">
							{{ t('screenings', 'Remove password') }}
						</NcButton>
					</div>
					<NcDateTimePickerNative v-model="form.expiresAt"
						type="datetime-local"
						:label="t('screenings', 'Expires (optional)')"
						clearable />
					<NcCheckboxRadioSwitch v-model="form.openLinkEnabled" type="switch">
						{{ t('screenings', 'Open link: anyone with it can watch after confirming their email') }}
					</NcCheckboxRadioSwitch>
					<div v-if="screening.openLinkEnabled" class="screening__open-link">
						<code>{{ openLink }}</code>
						<NcButton :aria-label="t('screenings', 'Copy open link')" variant="tertiary" @click="copyLink(openLink)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiContentCopy" />
							</template>
						</NcButton>
						<NcButton variant="tertiary" @click="regenerateOpenLink">
							{{ t('screenings', 'New link') }}
						</NcButton>
					</div>
				</section>

				<section class="screening__section">
					<h3>{{ t('screenings', 'Playback and data') }}</h3>
					<NcCheckboxRadioSwitch v-model="form.watermark" type="switch">
						{{ t('screenings', 'Show the viewer\'s name and email as a watermark') }}
					</NcCheckboxRadioSwitch>
					<NcTextField v-model="form.retentionDays"
						type="number"
						min="1"
						max="3650"
						:label="t('screenings', 'Keep viewing data for (days)')" />
				</section>

				<div class="screening__save">
					<NcButton variant="primary" type="submit" :disabled="!dirty || saving">
						{{ saving ? t('screenings', 'Saving…') : t('screenings', 'Save changes') }}
					</NcButton>
				</div>
			</form>

			<SubtitlesSection :screening-id="screening.id" :subtitles="screening.subtitles" />
			<InvitesSection :screening-id="screening.id"
				:invites="screening.invites"
				@count="(count) => emit('updated', { id: screening.id, inviteCount: count })" />
		</template>
	</div>
</template>

<script setup>
import { mdiContentCopy, mdiDeleteOutline, mdiFolderOpenOutline } from '@mdi/js'
import { getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'
import api, { errorMessage, publicLink } from '../api.js'
import { copyLink } from '../clipboard.js'
import InvitesSection from './InvitesSection.vue'
import SubtitlesSection from './SubtitlesSection.vue'

const props = defineProps({
	screeningId: { type: Number, required: true },
})
const emit = defineEmits(['updated', 'deleted'])

const availableModes = loadState('screenings', 'deliveryModes', ['mp4', 'hls'])
const allModes = [
	{
		id: 'mp4',
		label: t('screenings', 'Direct MP4'),
		description: t('screenings', 'Streams the MP4 as is. Works on every server, but segments are not encrypted (lower protection).'),
	},
	{
		id: 'hls',
		label: t('screenings', 'Pre-packaged HLS'),
		description: t('screenings', 'You convert the film to HLS on your own computer and upload the folder. The server only encrypts it.'),
	},
	{
		id: 'transcode',
		label: t('screenings', 'Server transcode'),
		description: t('screenings', 'The server converts the video to encrypted HLS in several qualities. Needs ffmpeg.'),
	},
]
const modes = allModes.filter((mode) => availableModes.includes(mode.id))

const statusLabels = {
	none: t('screenings', 'No video selected'),
	pending: t('screenings', 'Waiting for processing'),
	processing: t('screenings', 'Processing'),
	ready: t('screenings', 'Ready'),
	error: t('screenings', 'Error'),
}

const screening = ref(null)
const form = ref({})
const saving = ref(false)

const openLink = computed(() => publicLink(screening.value.openLinkToken))
const statusLabel = computed(() => {
	const s = screening.value
	if (s.mediaStatus === 'processing') {
		return `${statusLabels.processing} (${s.mediaProgress}%)`
	}
	if (s.mediaStatus === 'error' && s.mediaError) {
		return `${statusLabels.error}: ${s.mediaError}`
	}
	return statusLabels[s.mediaStatus] ?? s.mediaStatus
})

function toForm(s) {
	return {
		title: s.title,
		description: s.description ?? '',
		deliveryMode: s.deliveryMode,
		sourcePath: s.sourcePath,
		password: '',
		expiresAt: s.expiresAt ? new Date(s.expiresAt * 1000) : null,
		openLinkEnabled: s.openLinkEnabled,
		watermark: s.watermark,
		retentionDays: String(s.retentionDays),
	}
}

/** Only the fields that differ from the saved screening. */
const changes = computed(() => {
	const s = screening.value
	if (!s) {
		return {}
	}
	const f = form.value
	const out = {}
	if (f.title !== s.title) out.title = f.title
	if (f.description !== (s.description ?? '')) out.description = f.description
	if (f.deliveryMode !== s.deliveryMode || f.sourcePath !== s.sourcePath) {
		// Mode and file are validated together on the server.
		out.deliveryMode = f.deliveryMode
		out.sourcePath = f.sourcePath
	}
	if (f.password !== '') out.password = f.password
	const expiresAt = f.expiresAt ? Math.floor(f.expiresAt.getTime() / 1000) : null
	if (expiresAt !== s.expiresAt) out.expiresAt = expiresAt
	if (f.openLinkEnabled !== s.openLinkEnabled) out.openLinkEnabled = f.openLinkEnabled
	if (f.watermark !== s.watermark) out.watermark = f.watermark
	if (Number(f.retentionDays) !== s.retentionDays) out.retentionDays = Number(f.retentionDays)
	return out
})
const dirty = computed(() => Object.keys(changes.value).length > 0)

function applySaved(saved) {
	screening.value = { ...screening.value, ...saved }
	form.value = toForm(screening.value)
	emit('updated', saved)
}

async function load() {
	try {
		screening.value = await api.getScreening(props.screeningId)
		form.value = toForm(screening.value)
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not load the screening')))
	}
}

async function save() {
	saving.value = true
	try {
		applySaved(await api.updateScreening(props.screeningId, changes.value))
		showSuccess(t('screenings', 'Saved'))
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not save')))
	} finally {
		saving.value = false
	}
}

async function removePassword() {
	try {
		applySaved(await api.updateScreening(props.screeningId, { password: '' }))
		showSuccess(t('screenings', 'Password removed'))
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not remove the password')))
	}
}

async function regenerateOpenLink() {
	if (!window.confirm(t('screenings', 'The current open link will stop working. Continue?'))) {
		return
	}
	try {
		applySaved(await api.regenerateOpenLink(props.screeningId))
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not create a new link')))
	}
}

async function pickSource() {
	const hls = form.value.deliveryMode === 'hls'
	// Nextcloud maps .m3u8 to audio/mpegurl. Folders stay visible for navigation.
	const picker = getFilePickerBuilder(hls ? t('screenings', 'Choose the HLS playlist') : t('screenings', 'Choose the film'))
		.allowDirectories(false)
		.setMimeTypeFilter(hls ? ['audio/mpegurl', 'application/vnd.apple.mpegurl'] : ['video/mp4', 'video/quicktime', 'video/x-matroska', 'video/webm'])
		.build()
	try {
		form.value.sourcePath = await picker.pick()
	} catch {
		// Picker closed without a choice.
	}
}

async function remove() {
	if (!window.confirm(t('screenings', 'Delete "{title}" with all its invites and viewing data? This cannot be undone.', { title: screening.value.title }))) {
		return
	}
	try {
		await api.deleteScreening(props.screeningId)
		emit('deleted', props.screeningId)
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not delete the screening')))
	}
}

onMounted(load)
</script>

<style scoped>
.screening {
	max-width: 860px;
	padding: 16px 24px 48px 56px;
}

.screening__loading {
	display: flex;
	justify-content: center;
	padding-top: 20vh;
}

.screening__header {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: 44px;
}

.screening__header h2 {
	margin: 0;
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.screening__section {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: 24px;
}

.screening__section h3 {
	margin: 0;
	font-size: 1.1em;
}

.screening__source,
.screening__password,
.screening__open-link {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.screening__password > :first-child {
	flex: 1;
	min-width: 220px;
}

.screening__source-path {
	flex: 1;
	min-width: 0;
	overflow-wrap: anywhere;
	color: var(--color-text-maxcontrast);
}

.screening__open-link code {
	flex: 1;
	min-width: 0;
	overflow-wrap: anywhere;
	padding: 6px 8px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.screening__status {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.screening__save {
	margin-top: 24px;
}

@media (max-width: 600px) {
	.screening {
		padding: 16px;
	}

	/* Leave room for the navigation toggle in the top-left corner. */
	.screening__header {
		margin-inline-start: 40px;
	}
}
</style>

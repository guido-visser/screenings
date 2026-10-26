<template>
	<div class="screening" :class="{ 'screening--wide': view !== 'settings' }">
		<div v-if="!screening" class="screening__loading">
			<NcLoadingIcon :size="44" />
		</div>
		<template v-else>
			<header class="screening__header">
				<h2>{{ screening.title }}</h2>
				<nav class="screening__tabs" :aria-label="t('screenings', 'Screening sections')">
					<a :href="screeningHash(screening.id, 'dashboard')"
						class="screening__tab"
						:class="{ 'screening__tab--active': view !== 'settings' }"
						:aria-current="view !== 'settings' ? 'page' : undefined">
						{{ t('screenings', 'Dashboard') }}
					</a>
					<a :href="screeningHash(screening.id, 'settings')"
						class="screening__tab"
						:class="{ 'screening__tab--active': view === 'settings' }"
						:aria-current="view === 'settings' ? 'page' : undefined">
						{{ t('screenings', 'Settings') }}
					</a>
				</nav>
				<NcActions>
					<NcActionButton @click="remove">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDeleteOutline" />
						</template>
						{{ t('screenings', 'Delete screening') }}
					</NcActionButton>
				</NcActions>
			</header>

			<DashboardView v-if="view === 'dashboard'" :screening="screening" />
			<ViewerDetail v-else-if="view === 'viewer'"
				:key="inviteId"
				:screening="screening"
				:invite-id="inviteId" />

			<template v-else>
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
					<HlsHelp v-if="form.deliveryMode === 'hls' && !screening.sourcePath" />
					<MediaStatus v-if="!mediaFormChanged"
						:screening="screening"
						:busy="reprocessing"
						@reprocess="reprocess" />
					<p v-else class="screening__status">
						{{ t('screenings', 'Save to prepare the selected video.') }}
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
					<NcCheckboxRadioSwitch v-model="form.trackingNoticeEnabled" type="switch">
						{{ t('screenings', 'Tell viewers that their viewing is tracked') }}
					</NcCheckboxRadioSwitch>
					<NcTextArea v-if="form.trackingNoticeEnabled"
						v-model="form.trackingNotice"
						:label="t('screenings', 'Tracking notice')"
						:helper-text="t('screenings', 'Shown on the screening page and in invitation emails. Empty it to go back to the default text.')"
						maxlength="500"
						resize="vertical" />
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
				:has-password="screening.hasPassword"
				@count="(count) => emit('updated', { id: screening.id, inviteCount: count })" />
			</template>
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
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import api, { errorMessage, publicLink } from '../api.js'
import { copyLink } from '../clipboard.js'
import HlsHelp from './HlsHelp.vue'
import InvitesSection from './InvitesSection.vue'
import { screeningHash } from '../router.js'
import DashboardView from './DashboardView.vue'
import MediaStatus from './MediaStatus.vue'
import SubtitlesSection from './SubtitlesSection.vue'
import ViewerDetail from './ViewerDetail.vue'

const props = defineProps({
	screeningId: { type: Number, required: true },
	/** "dashboard", "settings" or "viewer" */
	view: { type: String, default: 'dashboard' },
	inviteId: { type: Number, default: null },
})
const emit = defineEmits(['updated', 'deleted'])

const availableModes = loadState('screenings', 'deliveryModes', ['auto', 'mp4', 'hls'])
const allModes = [
	{
		id: 'auto',
		label: t('screenings', 'Auto (recommended)'),
		description: t('screenings', 'Plays videos that browsers can play directly, and converts everything else on the server (ProRes, DNxHR, MKV, HEVC, 10-bit, PCM or Dolby audio, and more).'),
	},
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

const MP4_MIMES = ['video/mp4', 'video/x-m4v', 'video/quicktime']
// Nextcloud has no type for these; see ScreeningService::OTHER_VIDEO_EXTENSIONS.
const VIDEO_EXTENSIONS = ['mxf']

/** Which files the picker offers; Direct MP4 only plays MP4/MOV as they are. */
function videoFilter(mode) {
	return (node) => {
		if (node.type === 'folder') {
			return true
		}
		if (mode === 'mp4') {
			return MP4_MIMES.includes(node.mime)
		}
		return node.mime?.startsWith('video/') || VIDEO_EXTENSIONS.includes(node.extension?.replace('.', '').toLowerCase())
	}
}

const MEDIA_FIELDS = ['mediaStatus', 'mediaProgress', 'mediaError', 'mediaWarning', 'mediaMode', 'durationMs']
const POLL_MS = 3000

const screening = ref(null)
const form = ref({})
const saving = ref(false)
const reprocessing = ref(false)
let pollTimer = null

const openLink = computed(() => publicLink(screening.value.openLinkToken))
const mediaFormChanged = computed(() => form.value.deliveryMode !== screening.value.deliveryMode
	|| form.value.sourcePath !== screening.value.sourcePath)
const mediaBusy = computed(() => ['pending', 'processing'].includes(screening.value?.mediaStatus))

/**
 * While media is processing, refresh only the media fields so the owner's
 * unsaved edits in the form are left alone.
 */
async function poll() {
	pollTimer = null
	try {
		const fresh = await api.getScreening(props.screeningId)
		for (const field of MEDIA_FIELDS) {
			screening.value[field] = fresh[field]
		}
	} catch {
		// Try again on the next tick.
	}
	schedulePoll()
}

function schedulePoll() {
	if (pollTimer === null && mediaBusy.value) {
		pollTimer = window.setTimeout(poll, POLL_MS)
	}
}

watch(mediaBusy, schedulePoll)
onBeforeUnmount(() => window.clearTimeout(pollTimer))

async function reprocess() {
	reprocessing.value = true
	try {
		applySaved(await api.reprocessMedia(props.screeningId))
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not start processing')))
	} finally {
		reprocessing.value = false
	}
}

// Keep in sync with TrackingNotice::text() on the server.
const defaultTrackingNotice = t('screenings', 'Your viewing behaviour is tracked.')

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
		trackingNoticeEnabled: s.trackingNoticeEnabled,
		trackingNotice: s.trackingNotice ?? defaultTrackingNotice,
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
	if (f.trackingNoticeEnabled !== s.trackingNoticeEnabled) out.trackingNoticeEnabled = f.trackingNoticeEnabled
	// The default text (or an emptied field) is stored as "no custom text", so it stays translated.
	const notice = f.trackingNotice.trim() === defaultTrackingNotice ? '' : f.trackingNotice.trim()
	if (notice !== (s.trackingNotice ?? '')) out.trackingNotice = notice
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
	const builder = getFilePickerBuilder(hls ? t('screenings', 'Choose the HLS playlist') : t('screenings', 'Choose the film'))
		.allowDirectories(false)
	if (hls) {
		builder.setMimeTypeFilter(['audio/mpegurl', 'application/vnd.apple.mpegurl'])
	} else {
		builder.setFilter(videoFilter(form.value.deliveryMode))
	}
	const picker = builder.build()
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

.screening--wide {
	max-width: 1200px;
}

.screening__header {
	flex-wrap: wrap;
}

.screening__tabs {
	display: flex;
	gap: 4px;
}

.screening__tab {
	padding: 8px 14px;
	border-radius: var(--border-radius-element, 8px);
	color: var(--color-main-text);
	font-weight: 500;
}

.screening__tab:hover {
	background: var(--color-background-hover);
}

.screening__tab--active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
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

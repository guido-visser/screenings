<template>
	<NcSettingsSection :name="t('screenings', 'Screenings: viewer locations')"
		:description="t('screenings', 'Optionally show the country and city of each viewing session, looked up on this server from the viewer\'s IP address with a MaxMind database. IP addresses are not sent anywhere.')">
		<NcNoteCard v-if="status.available" type="success">
			{{ t('screenings', 'Database ready: {type}, built {date}', { type: status.type, date: builtDate }) }}
		</NcNoteCard>
		<NcNoteCard v-else-if="status.error" type="warning">
			{{ errorText }}
		</NcNoteCard>

		<div class="geoip__modes" role="radiogroup">
			<NcCheckboxRadioSwitch v-for="option in modes"
				:key="option.id"
				:model-value="geoip.mode"
				type="radio"
				name="geoipMode"
				:value="option.id"
				:description="option.description"
				:disabled="saving"
				@update:model-value="(mode) => save({ mode })">
				{{ option.label }}
			</NcCheckboxRadioSwitch>
		</div>

		<form v-if="geoip.mode === 'maxmind'" class="geoip__form" @submit.prevent="saveCredentials">
			<NcTextField v-model="accountId" :label="t('screenings', 'MaxMind account ID')" inputmode="numeric" />
			<NcPasswordField v-model="licenseKey"
				:label="t('screenings', 'License key')"
				:placeholder="geoip.hasLicenseKey ? t('screenings', 'Saved; type to replace') : ''"
				autocomplete="off" />
			<div class="geoip__actions">
				<NcButton type="submit" :disabled="saving">
					{{ t('screenings', 'Save') }}
				</NcButton>
				<NcButton :disabled="saving || downloading || !geoip.hasLicenseKey || !geoip.accountId" @click="download">
					{{ downloading ? t('screenings', 'Downloading…') : t('screenings', 'Download now') }}
				</NcButton>
			</div>
			<p class="geoip__hint">
				{{ t('screenings', 'Create a free account at maxmind.com and generate a license key. The database (about 60 MB) is then refreshed weekly.') }}
			</p>
		</form>

		<form v-if="geoip.mode === 'file'" class="geoip__form" @submit.prevent="save({ path })">
			<NcTextField v-model="path" :label="t('screenings', 'Database file (.mmdb)')" placeholder="/var/lib/GeoIP/GeoLite2-City.mmdb" />
			<div class="geoip__actions">
				<NcButton type="submit" :disabled="saving">
					{{ t('screenings', 'Save') }}
				</NcButton>
			</div>
		</form>
	</NcSettingsSection>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref, watch } from 'vue'

const props = defineProps({
	geoip: { type: Object, required: true },
	saving: { type: Boolean, default: false },
	downloading: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'download'])

const accountId = ref(props.geoip.accountId)
const licenseKey = ref('')
const path = ref(props.geoip.path)
watch(() => props.geoip, (geoip) => {
	accountId.value = geoip.accountId
	path.value = geoip.path
	licenseKey.value = ''
})

const status = computed(() => props.geoip.status)
const builtDate = computed(() => status.value.builtAt ? new Date(status.value.builtAt * 1000).toLocaleDateString() : '')
const errorText = computed(() => ({
	not_downloaded: t('screenings', 'The database has not been downloaded yet.'),
	missing: t('screenings', 'The database file was not found at {path}.', { path: status.value.path }),
	invalid: t('screenings', 'The database file could not be read.'),
	wrong_type: t('screenings', 'This database has no location data. Use a GeoLite2 or GeoIP2 City database.'),
}[status.value.error] ?? status.value.error))

const modes = [
	{ id: 'off', label: t('screenings', 'Off'), description: t('screenings', 'Sessions show no location.') },
	{ id: 'maxmind', label: t('screenings', 'Download GeoLite2 from MaxMind'), description: t('screenings', 'Free, needs a MaxMind account.') },
	{ id: 'file', label: t('screenings', 'Use an existing database file'), description: t('screenings', 'For example one kept up to date by geoipupdate.') },
]

function save(geoip) {
	emit('save', { geoip })
}

function saveCredentials() {
	save({ accountId: accountId.value, licenseKey: licenseKey.value })
}

function download() {
	emit('download')
}
</script>

<style scoped>
.geoip__modes {
	margin: 12px 0;
}

.geoip__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 480px;
}

.geoip__actions {
	display: flex;
	gap: 8px;
}

.geoip__hint {
	color: var(--color-text-maxcontrast);
}
</style>

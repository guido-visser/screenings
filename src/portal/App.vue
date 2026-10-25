<template>
	<NcContent app-name="screenings">
		<NcAppNavigation :aria-label="t('screenings', 'Screenings')">
			<template #list>
				<NcAppNavigationNew :text="t('screenings', 'New screening')" @click="showCreate = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
				</NcAppNavigationNew>
				<NcAppNavigationItem v-for="screening in screenings"
					:key="screening.id"
					:name="screening.title"
					:active="screening.id === selectedId"
					@click="select(screening.id)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiMovieOpenOutline" />
					</template>
					<template #counter>
						<NcCounterBubble v-if="screening.inviteCount" :count="screening.inviteCount" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<div v-if="loading" class="screenings-loading">
				<NcLoadingIcon :size="44" />
			</div>
			<ScreeningView v-else-if="selectedId !== null"
				:key="selectedId"
				:screening-id="selectedId"
				@updated="onUpdated"
				@deleted="onDeleted" />
			<NcEmptyContent v-else
				:name="screenings.length ? t('screenings', 'Select a screening') : t('screenings', 'No screenings yet')"
				:description="t('screenings', 'Create a screening to start sending tracked links.')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiMovieOpenOutline" />
				</template>
				<template #action>
					<NcButton variant="primary" @click="showCreate = true">
						{{ t('screenings', 'New screening') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</NcAppContent>

		<!-- Kept mounted (not v-if): the dialog closes itself on submit, and
			unmounting it first would cancel the form submission. -->
		<NcDialog v-model:open="showCreate"
			:name="t('screenings', 'New screening')"
			is-form
			:buttons="createButtons"
			@submit="create">
			<NcTextField v-model="newTitle"
				:label="t('screenings', 'Title')"
				:placeholder="t('screenings', 'e.g. Rough cut v3 for distributors')"
				required />
		</NcDialog>
	</NcContent>
</template>

<script setup>
import { mdiMovieOpenOutline, mdiPlus } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import api, { errorMessage } from './api.js'
import ScreeningView from './components/ScreeningView.vue'

const screenings = ref([])
const loading = ref(true)
const selectedId = ref(idFromHash())
const showCreate = ref(false)
const newTitle = ref('')

// The button calls create() itself: a native submit button would close the
// dialog (removing the form) before the browser fires the submit event.
// Enter in the title field still submits the form, which also calls create().
const createButtons = computed(() => [{
	label: t('screenings', 'Create'),
	variant: 'primary',
	disabled: newTitle.value.trim() === '',
	callback: async () => (await create()) ? undefined : false,
}])

function idFromHash() {
	const match = window.location.hash.match(/^#\/screenings\/(\d+)/)
	return match ? Number(match[1]) : null
}

function onHashChange() {
	selectedId.value = idFromHash()
}

function select(id) {
	window.location.hash = id === null ? '' : `#/screenings/${id}`
	selectedId.value = id
}

async function load() {
	try {
		screenings.value = await api.listScreenings()
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not load screenings')))
	} finally {
		loading.value = false
	}
}

/** @return {Promise<boolean>} whether the screening was created */
async function create() {
	if (newTitle.value.trim() === '') {
		return false
	}
	try {
		const screening = await api.createScreening({ title: newTitle.value })
		screenings.value.unshift({ ...screening, inviteCount: 0 })
		showCreate.value = false
		newTitle.value = ''
		select(screening.id)
		return true
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not create the screening')))
		return false
	}
}

function onUpdated(screening) {
	const index = screenings.value.findIndex((s) => s.id === screening.id)
	if (index !== -1) {
		screenings.value[index] = { ...screenings.value[index], ...screening }
	}
}

function onDeleted(id) {
	screenings.value = screenings.value.filter((s) => s.id !== id)
	select(null)
}

onMounted(() => {
	window.addEventListener('hashchange', onHashChange)
	load()
})
onBeforeUnmount(() => window.removeEventListener('hashchange', onHashChange))
</script>

<style scoped>
.screenings-loading {
	display: flex;
	justify-content: center;
	padding-top: 20vh;
}
</style>

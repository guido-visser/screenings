<template>
	<section class="questions">
		<h3>{{ t('screenings', 'Closing questions') }}</h3>
		<p class="questions__hint">
			{{ t('screenings', 'Asked when a viewer reaches the end of the film, or whenever they click "Give feedback". Only invites with "Questions" turned on see them (Invites below); open-link visitors never do. Viewers can change their answers later.') }}
		</p>

		<div v-if="loading" class="questions__loading">
			<NcLoadingIcon :size="28" />
		</div>
		<template v-else>
			<ol v-if="items.length" class="questions__list">
				<li v-for="(item, index) in items" :key="item.key" class="questions__item">
					<div class="questions__row">
						<span class="questions__number">{{ index + 1 }}</span>
						<NcTextField v-model="item.label"
							class="questions__label"
							:label="t('screenings', 'Question')"
							maxlength="255" />
						<label class="questions__kind">
							<span class="hidden-visually">{{ t('screenings', 'Kind of answer') }}</span>
							<select v-model="item.kind">
								<option value="rating">{{ t('screenings', 'Rating (1–5 stars)') }}</option>
								<option value="choice">{{ t('screenings', 'Multiple choice') }}</option>
								<option value="text">{{ t('screenings', 'Free text') }}</option>
							</select>
						</label>
					</div>
					<NcTextArea v-if="item.kind === 'choice'"
						v-model="item.optionsText"
						:label="t('screenings', 'Options, one per line')"
						rows="3"
						resize="vertical" />
					<div class="questions__row questions__row--end">
						<NcCheckboxRadioSwitch v-model="item.required" type="switch">
							{{ t('screenings', 'Answer required') }}
						</NcCheckboxRadioSwitch>
						<span class="questions__spacer" />
						<NcButton variant="tertiary"
							:disabled="index === 0"
							:aria-label="t('screenings', 'Move up')"
							@click="move(index, -1)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiArrowUp" />
							</template>
						</NcButton>
						<NcButton variant="tertiary"
							:disabled="index === items.length - 1"
							:aria-label="t('screenings', 'Move down')"
							@click="move(index, 1)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiArrowDown" />
							</template>
						</NcButton>
						<NcButton variant="tertiary" :aria-label="t('screenings', 'Delete question')" @click="removeAt(index)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDeleteOutline" />
							</template>
						</NcButton>
					</div>
				</li>
			</ol>
			<p v-else class="questions__hint">
				{{ t('screenings', 'No questions yet.') }}
			</p>

			<div class="questions__actions">
				<NcButton :disabled="items.length >= MAX" @click="add">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('screenings', 'Add question') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!dirty || saving" @click="save">
					{{ saving ? t('screenings', 'Saving…') : t('screenings', 'Save questions') }}
				</NcButton>
				<span v-if="removedAnswered" class="questions__warning">
					{{ t('screenings', 'Answers to deleted questions, or to questions whose kind you changed, are deleted when you save.') }}
				</span>
			</div>
		</template>
	</section>
</template>

<script setup>
import { mdiArrowDown, mdiArrowUp, mdiDeleteOutline, mdiPlus } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'
import api, { errorMessage } from '../api.js'

const props = defineProps({
	screeningId: { type: Number, required: true },
})

const MAX = 10

const loading = ref(true)
const saving = ref(false)
const items = ref([])
const saved = ref([])
let nextKey = 1

function toItem(question) {
	return {
		key: nextKey++,
		id: question.id ?? null,
		kind: question.kind,
		label: question.label,
		optionsText: (question.options ?? []).join('\n'),
		required: Boolean(question.required),
	}
}

function toPayload(item) {
	return {
		id: item.id,
		kind: item.kind,
		label: item.label,
		options: item.kind === 'choice' ? item.optionsText.split('\n').map((o) => o.trim()).filter(Boolean) : [],
		required: item.required,
	}
}

const dirty = computed(() => JSON.stringify(items.value.map(toPayload)) !== JSON.stringify(saved.value.map(toPayload)))
// Saved questions that will lose their answers: deleted, or another kind.
const removedAnswered = computed(() => saved.value.some((old) => {
	const now = items.value.find((item) => item.id === old.id)
	return !now || now.kind !== old.kind
}))

function add() {
	items.value.push(toItem({ kind: 'rating', label: '' }))
}

function move(index, delta) {
	const list = [...items.value]
	const [item] = list.splice(index, 1)
	list.splice(index + delta, 0, item)
	items.value = list
}

function removeAt(index) {
	items.value = items.value.filter((_, i) => i !== index)
}

async function save() {
	saving.value = true
	try {
		const questions = await api.saveQuestions(props.screeningId, items.value.map(toPayload))
		saved.value = questions.map(toItem)
		items.value = questions.map(toItem)
		showSuccess(t('screenings', 'Questions saved'))
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not save the questions')))
	} finally {
		saving.value = false
	}
}

onMounted(async () => {
	try {
		const questions = await api.questions(props.screeningId)
		saved.value = questions.map(toItem)
		items.value = questions.map(toItem)
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not load the questions')))
	} finally {
		loading.value = false
	}
})
</script>

<style scoped>
.questions {
	margin-top: 32px;
}

.questions h3 {
	margin: 0 0 8px;
	font-size: 1.1em;
}

.questions__hint,
.questions__warning {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}

.questions__warning {
	margin: 0;
	color: var(--color-warning-text);
}

.questions__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin: 0 0 12px;
	padding: 0;
	list-style: none;
}

.questions__item {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.questions__row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.questions__number {
	min-width: 1.5em;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

.questions__label {
	flex: 1 1 240px;
}

.questions__kind select {
	min-height: var(--default-clickable-area, 34px);
	margin: 0;
}

.questions__spacer {
	flex: 1;
}

.questions__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}
</style>

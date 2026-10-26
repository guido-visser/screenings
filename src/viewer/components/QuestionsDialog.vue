<template>
	<!-- A modal <dialog> sits in the top layer, so it also shows over a fullscreen player. -->
	<dialog ref="dialog"
		class="questions-dialog"
		aria-labelledby="questions-title"
		@cancel.prevent="close">
		<form v-if="!done" class="questions-dialog__form" @submit.prevent="submit">
			<h2 id="questions-title">
				{{ t('screenings', 'A few questions from {owner}', { owner }) }}
			</h2>
			<p v-if="loading" class="questions-dialog__muted">
				{{ t('screenings', 'Loading…') }}
			</p>
			<p v-else-if="loadError" class="questions-dialog__error" role="alert">
				{{ loadError }}
			</p>

			<fieldset v-for="(question, index) in questions"
				:key="question.id"
				class="questions-dialog__question"
				:aria-invalid="errors['q' + question.id] ? 'true' : undefined">
				<legend>
					{{ index + 1 }}. {{ question.label }}
					<span v-if="question.required" class="questions-dialog__required" :title="t('screenings', 'Required')">*</span>
				</legend>

				<div v-if="question.kind === 'rating'" class="questions-dialog__stars" role="radiogroup" :aria-label="question.label">
					<label v-for="star in 5"
						:key="star"
						class="questions-dialog__star"
						:class="{ 'questions-dialog__star--on': Number(values[question.id]) >= star }">
						<input v-model="values[question.id]"
							type="radio"
							:name="'q' + question.id"
							:value="String(star)"
							class="hidden-visually">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="Number(values[question.id]) >= star ? mdiStar : mdiStarOutline" /></svg>
						<span class="hidden-visually">{{ n('screenings', '{count} star', '{count} stars', star, { count: star }) }}</span>
					</label>
				</div>

				<div v-else-if="question.kind === 'choice'" class="questions-dialog__choices">
					<label v-for="option in question.options" :key="option" class="questions-dialog__choice">
						<input v-model="values[question.id]"
							type="radio"
							:name="'q' + question.id"
							:value="option">
						{{ option }}
					</label>
				</div>

				<textarea v-else
					v-model="values[question.id]"
					rows="3"
					maxlength="2000"
					:aria-label="question.label" />

				<p v-if="errors['q' + question.id]" class="questions-dialog__error" role="alert">
					{{ errors['q' + question.id] }}
				</p>
			</fieldset>

			<p v-if="error" class="questions-dialog__error" role="alert">
				{{ error }}
			</p>
			<div class="questions-dialog__actions">
				<button type="button" class="questions-dialog__button" @click="close">
					{{ t('screenings', 'Not now') }}
				</button>
				<button type="submit" class="questions-dialog__button questions-dialog__button--primary" :disabled="saving || loading || !questions.length">
					{{ answeredBefore ? t('screenings', 'Save answers') : t('screenings', 'Send answers') }}
				</button>
			</div>
		</form>

		<div v-else class="questions-dialog__form">
			<h2 id="questions-title">
				{{ t('screenings', 'Thank you!') }}
			</h2>
			<p>{{ t('screenings', 'Your answers were sent to {owner}. You can change them here until the screening ends.', { owner }) }}</p>
			<div class="questions-dialog__actions">
				<button type="button" class="questions-dialog__button questions-dialog__button--primary" autofocus @click="close">
					{{ t('screenings', 'Close') }}
				</button>
			</div>
		</div>
	</dialog>
</template>

<script setup>
import { mdiStar, mdiStarOutline } from '@mdi/js'
import { n, t } from '@nextcloud/l10n'
import { ref } from 'vue'

const props = defineProps({
	/** The questions endpoint of this session. */
	url: { type: String, required: true },
	owner: { type: String, required: true },
})
const emit = defineEmits(['answered'])

const dialog = ref(null)
const loading = ref(false)
const loadError = ref(null)
const questions = ref([])
const values = ref({})
const errors = ref({})
const error = ref(null)
const saving = ref(false)
const done = ref(false)
const answeredBefore = ref(false)

// The answers route sits next to the questions route.
const answersUrl = () => props.url.replace(/\/questions$/, '/answers')

async function request(url, options = {}) {
	let response
	try {
		response = await fetch(url, { credentials: 'same-origin', ...options })
	} catch {
		throw Object.assign(new Error(t('screenings', 'No connection. Try again in a moment.')), { data: {} })
	}
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw Object.assign(new Error(data.message ?? t('screenings', 'Something went wrong. Try again.')), { data })
	}
	return data
}

function apply(data) {
	questions.value = data.questions
	values.value = Object.fromEntries(data.questions.map((q) => [q.id, data.answers[q.id] ?? '']))
	answeredBefore.value = Object.keys(data.answers).length > 0
}

async function open() {
	done.value = false
	errors.value = {}
	error.value = null
	if (!dialog.value.open) {
		dialog.value.showModal()
	}
	loading.value = true
	loadError.value = null
	try {
		apply(await request(props.url))
	} catch (e) {
		loadError.value = e.message
	} finally {
		loading.value = false
	}
}

function close() {
	dialog.value?.close()
}

async function submit() {
	saving.value = true
	errors.value = {}
	error.value = null
	try {
		const data = await request(answersUrl(), {
			method: 'PUT',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ answers: values.value }),
		})
		apply(data)
		done.value = true
		emit('answered')
	} catch (e) {
		if (e.data?.field) {
			errors.value = { [e.data.field]: e.message }
		} else {
			error.value = e.message
		}
	} finally {
		saving.value = false
	}
}

defineExpose({ open })
</script>

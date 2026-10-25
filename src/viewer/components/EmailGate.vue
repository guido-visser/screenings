<template>
	<form v-if="step === 'details'" class="viewer-form" @submit.prevent="sendCode">
		<p class="viewer-form__intro">
			{{ t('screenings', 'Enter your name and email address. We\'ll email you a code to confirm it\'s you.') }}
		</p>
		<label class="viewer-form__field">
			<span>{{ t('screenings', 'Name') }}</span>
			<input v-model="name" type="text" autocomplete="name" required autofocus maxlength="255">
		</label>
		<label class="viewer-form__field">
			<span>{{ t('screenings', 'Email') }}</span>
			<input v-model="email" type="email" autocomplete="email" required maxlength="255">
		</label>
		<p v-if="error" class="viewer-form__error" role="alert">
			{{ error }}
		</p>
		<button type="submit" class="viewer-form__button" :disabled="busy || !name.trim() || !email.trim()">
			{{ busy ? t('screenings', 'Sending…') : t('screenings', 'Email me a code') }}
		</button>
	</form>

	<form v-else class="viewer-form" @submit.prevent="verify">
		<p class="viewer-form__intro">
			{{ t('screenings', 'We sent a 6-digit code to {email}. It expires in 10 minutes.', { email }) }}
		</p>
		<label class="viewer-form__field">
			<span>{{ t('screenings', 'Code') }}</span>
			<input ref="codeInput"
				v-model="code"
				type="text"
				inputmode="numeric"
				autocomplete="one-time-code"
				pattern="[0-9 ]{6,7}"
				maxlength="7"
				required>
		</label>
		<p v-if="error" class="viewer-form__error" role="alert">
			{{ error }}
		</p>
		<button type="submit" class="viewer-form__button" :disabled="busy || code.replace(/\s/g, '').length !== 6">
			{{ busy ? t('screenings', 'Checking…') : t('screenings', 'Continue') }}
		</button>
		<p class="viewer-form__links">
			<button type="button" class="viewer-form__link" :disabled="busy" @click="sendCode">
				{{ t('screenings', 'Send a new code') }}
			</button>
			<button type="button" class="viewer-form__link" :disabled="busy" @click="changeEmail">
				{{ t('screenings', 'Use another email address') }}
			</button>
		</p>
	</form>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import { nextTick, ref } from 'vue'
import api, { errorMessage } from '../api.js'

const props = defineProps({
	token: { type: String, required: true },
})

const step = ref('details')
const name = ref('')
const email = ref('')
const code = ref('')
const error = ref('')
const busy = ref(false)
const codeInput = ref(null)

function failure(e) {
	return e?.response?.status === 429
		? t('screenings', 'Too many attempts. Wait a few minutes and try again.')
		: errorMessage(e, t('screenings', 'Something went wrong. Try again.'))
}

async function sendCode() {
	busy.value = true
	error.value = ''
	try {
		await api.requestCode(props.token, name.value, email.value)
		step.value = 'code'
		code.value = ''
		await nextTick()
		codeInput.value?.focus()
	} catch (e) {
		error.value = failure(e)
	} finally {
		busy.value = false
	}
}

async function verify() {
	busy.value = true
	error.value = ''
	try {
		window.location.href = await api.verifyCode(props.token, email.value, code.value)
	} catch (e) {
		error.value = failure(e)
		busy.value = false
	}
}

function changeEmail() {
	step.value = 'details'
	error.value = ''
}
</script>

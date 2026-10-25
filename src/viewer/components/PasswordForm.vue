<template>
	<form class="viewer-form" @submit.prevent="submit">
		<label class="viewer-form__field">
			<span>{{ t('screenings', 'Password') }}</span>
			<input v-model="password"
				type="password"
				autocomplete="current-password"
				required
				autofocus>
		</label>
		<p v-if="error" class="viewer-form__error" role="alert">
			{{ error }}
		</p>
		<button type="submit" class="viewer-form__button" :disabled="busy || !password">
			{{ busy ? t('screenings', 'Checking…') : t('screenings', 'Continue') }}
		</button>
	</form>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import api, { errorMessage } from '../api.js'

const props = defineProps({
	token: { type: String, required: true },
})

const password = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
	busy.value = true
	error.value = ''
	try {
		await api.unlock(props.token, password.value)
		// The server now recognises this browser; reload to start watching.
		window.location.reload()
	} catch (e) {
		error.value = e?.response?.status === 429
			? t('screenings', 'Too many attempts. Wait a moment and try again.')
			: errorMessage(e, t('screenings', 'Something went wrong. Try again.'))
		busy.value = false
	}
}
</script>

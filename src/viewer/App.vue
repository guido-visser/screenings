<template>
	<main class="viewer">
		<section v-if="message" class="viewer__card viewer__card--message">
			<h1>{{ message.title }}</h1>
			<p>{{ message.text }}</p>
		</section>

		<section v-else-if="state === 'email'" class="viewer__card">
			<ScreeningHeader :screening="screening" />
			<EmailGate :token="token" />
			<PrivacyNotice :owner="screening.owner" />
		</section>

		<section v-else-if="state === 'password'" class="viewer__card">
			<ScreeningHeader :screening="screening" :viewer="viewer" />
			<PasswordForm :token="token" />
			<PrivacyNotice :owner="screening.owner" />
		</section>

		<section v-else-if="state === 'ready'" class="viewer__screening">
			<ScreeningHeader :screening="screening" :viewer="viewer" wide />
			<div class="viewer__player">
				<p>{{ t('screenings', 'The player arrives in the next version of this app.') }}</p>
			</div>
			<PrivacyNotice :owner="screening.owner" />
		</section>
	</main>
</template>

<script setup>
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import EmailGate from './components/EmailGate.vue'
import PasswordForm from './components/PasswordForm.vue'
import PrivacyNotice from './components/PrivacyNotice.vue'
import ScreeningHeader from './components/ScreeningHeader.vue'

const { state, token, screening, viewer } = loadState('screenings', 'viewer')

if (screening?.title) {
	document.title = screening.title
}

const message = computed(() => {
	switch (state) {
	case 'unavailable':
		return { title: t('screenings', 'Link not available'), text: t('screenings', 'This screening link does not exist or is no longer active.') }
	case 'revoked':
		return { title: t('screenings', 'Access revoked'), text: t('screenings', 'The sender has withdrawn access to this screening.') }
	case 'expired':
		return {
			title: t('screenings', 'This screening has ended'),
			text: screening
				? t('screenings', '"{title}" is no longer available.', { title: screening.title })
				: t('screenings', 'This screening is no longer available.'),
		}
	case 'not_ready':
		return { title: screening.title, text: t('screenings', 'The film is still being prepared. Please try again later.') }
	default:
		return null
	}
})
</script>

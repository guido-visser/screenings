<template>
	<main class="viewer">
		<section v-if="message" class="viewer__card viewer__card--message">
			<h1>{{ message.title }}</h1>
			<p>{{ message.text }}</p>
		</section>

		<section v-else-if="state === 'email'" class="viewer__card">
			<ScreeningHeader :screening="screening" />
			<EmailGate :token="token" />
			<PrivacyNotice v-if="screening.trackingNotice" :text="screening.trackingNotice" />
		</section>

		<section v-else-if="state === 'password'" class="viewer__card">
			<ScreeningHeader :screening="screening" :viewer="viewer" />
			<PasswordForm :token="token" />
			<PrivacyNotice v-if="screening.trackingNotice" :text="screening.trackingNotice" />
		</section>

		<section v-else-if="state === 'ready'" class="viewer__screening">
			<ScreeningHeader :screening="screening" :viewer="viewer" wide />
			<Player ref="player"
				:playback="playback"
				:screening="screening"
				:viewer="viewer"
				:notes="notes"
				@ended="onEnded" />
			<div v-if="questions" class="feedback-bar" :class="{ 'feedback-bar--prompt': prompt }">
				<p v-if="prompt" class="feedback-bar__text">
					{{ n('screenings', 'You watched to the end earlier. Would you answer a question from {owner}?', 'You watched to the end earlier. Would you answer {count} short questions from {owner}?', questions.count, { owner: screening.owner, count: questions.count }) }}
				</p>
				<p v-else class="feedback-bar__text">
					{{ n('screenings', '{owner} has a question for you about the film.', '{owner} has {count} short questions for you about the film.', questions.count, { owner: screening.owner, count: questions.count }) }}
				</p>
				<button class="feedback-bar__button" @click="openQuestions">
					{{ answered ? t('screenings', 'Change my answers') : t('screenings', 'Give feedback') }}
				</button>
			</div>
			<QuestionsDialog v-if="questions"
				ref="questionsDialog"
				:url="questions.url"
				:owner="screening.owner"
				@answered="onAnswered" />
			<NotesPanel v-if="notes" :notes="notes" :owner="screening.owner" @seek="seekFromNotes" />
			<PrivacyNotice v-if="screening.trackingNotice" :text="screening.trackingNotice" />
		</section>
	</main>
</template>

<script setup>
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '@nextcloud/l10n'
import { computed, defineAsyncComponent, ref } from 'vue'
import EmailGate from './components/EmailGate.vue'
import NotesPanel from './components/NotesPanel.vue'
import PasswordForm from './components/PasswordForm.vue'
import PrivacyNotice from './components/PrivacyNotice.vue'
import QuestionsDialog from './components/QuestionsDialog.vue'
import ScreeningHeader from './components/ScreeningHeader.vue'
import { createNotes } from './notes.js'

// hls.js is large; only load it once there is something to play.
const Player = defineAsyncComponent(() => import('./components/Player.vue'))

const { state, token, screening, viewer, playback } = loadState('screenings', 'viewer')

const player = ref(null)
// Only for invites the owner asked for notes.
const notes = playback?.notesUrl ? createNotes(playback.notesUrl) : null

// Closing questions ({url, count, pending}) for invites the owner asks.
const questions = playback?.questions ?? null
const questionsDialog = ref(null)
// Finished the film on an earlier visit without answering: ask once, softly.
const prompt = ref(Boolean(questions?.pending))
const answered = ref(Boolean(questions?.answered))
let askedAtEnd = false

function openQuestions() {
	prompt.value = false
	questionsDialog.value?.open()
}

/** The film reached its end: ask the questions, once per visit. */
function onEnded() {
	if (questions && !answered.value && !askedAtEnd) {
		askedAtEnd = true
		openQuestions()
	}
}

function onAnswered() {
	answered.value = true
	prompt.value = false
}

/** A note's time in the list was clicked: bring the player into view there. */
function seekFromNotes(position) {
	player.value?.seekTo(position)
	document.querySelector('.player')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

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
	case 'busy':
		return { title: screening.title, text: t('screenings', 'Too many new visits from your network. Please try again in an hour.') }
	case 'not_ready':
		return { title: screening.title, text: t('screenings', 'The film is still being prepared. Please try again later.') }
	default:
		return null
	}
})
</script>

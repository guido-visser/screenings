<template>
	<div class="answers">
		<div v-if="!data" class="answers__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<NcEmptyContent v-else-if="!data.questions.length"
			:name="t('screenings', 'No closing questions yet')"
			:description="t('screenings', 'Add questions in Settings, then turn on Questions for the invites that should get them.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiCommentQuestionOutline" />
			</template>
		</NcEmptyContent>
		<template v-else>
			<div class="answers__toolbar">
				<p class="answers__summary">
					{{ n('screenings', '{answered} of {asked} asked viewer answered', '{answered} of {asked} asked viewers answered', data.asked, { answered: data.viewers.length, asked: data.asked }) }}
				</p>
				<NcButton v-if="data.viewers.length" :href="api.answersExportUrl(screening.id)" download>
					<template #icon>
						<NcIconSvgWrapper :path="mdiDownload" />
					</template>
					{{ t('screenings', 'Download as spreadsheet (CSV)') }}
				</NcButton>
			</div>

			<section v-for="(question, index) in data.questions" :key="question.id" class="answers__question">
				<h3>{{ index + 1 }}. {{ question.label }}</h3>
				<p class="answers__muted">
					{{ n('screenings', '{count} answer', '{count} answers', question.summary.count, { count: question.summary.count }) }}
				</p>

				<div v-if="question.kind === 'rating' && question.summary.count" class="answers__rating">
					<div class="answers__average">
						<span class="answers__average-value">{{ formatAverage(question.summary.average) }}</span>
						<span class="answers__muted">{{ t('screenings', 'average of 5') }}</span>
					</div>
					<ol class="answers__bars" reversed>
						<li v-for="star in [5, 4, 3, 2, 1]" :key="star" class="answers__bar-row">
							<span class="answers__bar-label">{{ n('screenings', '{count} star', '{count} stars', star, { count: star }) }}</span>
							<span class="answers__bar"><span class="answers__bar-fill" :style="{ width: share(question.summary.distribution[star - 1], question.summary.count) }" /></span>
							<span class="answers__bar-count">{{ question.summary.distribution[star - 1] }}</span>
						</li>
					</ol>
				</div>

				<ul v-else-if="question.kind === 'choice' && question.summary.count" class="answers__bars">
					<li v-for="option in question.summary.options" :key="option.option" class="answers__bar-row">
						<span class="answers__bar-label">{{ option.option }}</span>
						<span class="answers__bar"><span class="answers__bar-fill" :style="{ width: share(option.count, question.summary.count) }" /></span>
						<span class="answers__bar-count">{{ option.count }}</span>
					</li>
					<li v-if="question.summary.other" class="answers__bar-row">
						<span class="answers__bar-label answers__muted">{{ t('screenings', 'Options you removed') }}</span>
						<span class="answers__bar"><span class="answers__bar-fill" :style="{ width: share(question.summary.other, question.summary.count) }" /></span>
						<span class="answers__bar-count">{{ question.summary.other }}</span>
					</li>
				</ul>

				<ul v-else-if="question.kind === 'text'" class="answers__texts">
					<li v-for="viewer in textAnswers(question)"
						:key="viewer.inviteId"
						:class="{ 'answers__text--fresh': fresh.has(viewer.inviteId) }">
						<strong>{{ viewer.name }}</strong>
						<span v-if="fresh.has(viewer.inviteId)" class="answers__new">{{ fresh.get(viewer.inviteId) }}</span>
						<p>{{ viewer.text }}</p>
					</li>
				</ul>
			</section>

			<section v-if="data.viewers.length" class="answers__question">
				<h3>{{ t('screenings', 'By viewer') }}</h3>
				<div class="answers__table-wrap">
					<table class="answers__table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('screenings', 'Viewer') }}
								</th>
								<th v-for="(question, index) in data.questions" :key="question.id" scope="col" :title="question.label">
									{{ index + 1 }}
								</th>
								<th scope="col">
									{{ t('screenings', 'Answered') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="viewer in data.viewers"
								:key="viewer.inviteId"
								:class="{ 'answers__row--fresh': fresh.has(viewer.inviteId) }">
								<th scope="row">
									<a :href="screeningHash(screening.id, 'viewer', viewer.inviteId)">{{ viewer.name }}</a>
									<span v-if="fresh.has(viewer.inviteId)" class="answers__new">{{ fresh.get(viewer.inviteId) }}</span>
								</th>
								<td v-for="question in data.questions" :key="question.id">
									{{ cell(question, viewer.answers[question.id]) }}
								</td>
								<td class="answers__muted">
									{{ formatRelative(viewer.answeredAt) }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</template>
	</div>
</template>

<script setup>
import { mdiCommentQuestionOutline, mdiDownload } from '@mdi/js'
import { showError, showInfo } from '@nextcloud/dialogs'
import { getLanguage, n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import api, { errorMessage } from '../api.js'
import { formatRelative } from '../format.js'
import { startPolling } from '../poller.js'
import { screeningHash } from '../router.js'

const props = defineProps({
	screening: { type: Object, required: true },
})

const data = ref(null)
// Viewers who answered (or changed answers) while the page was open: inviteId => label.
const fresh = ref(new Map())
let stopPolling = null

const LIVE_POLL_MS = 5000

/** Takes new answers in place, marking and announcing who answered. */
function apply(next) {
	const before = new Map((data.value?.viewers ?? []).map((v) => [v.inviteId, v.answeredAt]))
	if (data.value) {
		const marks = new Map(fresh.value)
		for (const viewer of next.viewers) {
			const old = before.get(viewer.inviteId)
			if (old === undefined) {
				marks.set(viewer.inviteId, t('screenings', 'New'))
				showInfo(t('screenings', '{name} answered your questions', { name: viewer.name }))
			} else if (viewer.answeredAt > old) {
				marks.set(viewer.inviteId, t('screenings', 'Updated'))
				showInfo(t('screenings', '{name} changed their answers', { name: viewer.name }))
			}
		}
		fresh.value = marks
	}
	data.value = next
}

function share(count, total) {
	return total ? Math.round((count / total) * 100) + '%' : '0%'
}

function formatAverage(value) {
	return value === null ? '–' : value.toLocaleString(getLanguage(), { maximumFractionDigits: 1 })
}

function textAnswers(question) {
	return data.value.viewers
		.filter((viewer) => viewer.answers[question.id])
		.map((viewer) => ({ inviteId: viewer.inviteId, name: viewer.name, text: viewer.answers[question.id] }))
}

/** A short cell for the by-viewer table. */
function cell(question, value) {
	if (value === undefined || value === '') {
		return '–'
	}
	if (question.kind === 'rating') {
		return '★'.repeat(Number(value))
	}
	return value.length > 60 ? value.slice(0, 59) + '…' : value
}

onMounted(async () => {
	try {
		apply(await api.answers(props.screening.id))
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not load the answers')))
		data.value = { asked: 0, questions: [], viewers: [] }
	}
	stopPolling = startPolling(async () => apply(await api.answers(props.screening.id)), LIVE_POLL_MS)
})

onBeforeUnmount(() => stopPolling?.())
</script>

<style scoped>
.answers__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.answers__toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 8px 16px;
	margin-bottom: 16px;
}

.answers__summary {
	margin: 0;
	font-weight: 600;
}

.answers__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.answers__question {
	margin-bottom: 16px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.answers__question h3 {
	margin: 0 0 2px;
	font-size: 1.05em;
	overflow-wrap: anywhere;
}

.answers__rating {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 16px 32px;
	margin-top: 12px;
}

.answers__average {
	display: flex;
	flex-direction: column;
}

.answers__average-value {
	font-size: 2.2em;
	font-weight: 600;
	line-height: 1.1;
	font-variant-numeric: tabular-nums;
}

.answers__bars {
	flex: 1 1 280px;
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 12px 0 0;
	padding: 0;
	list-style: none;
}

.answers__rating .answers__bars {
	margin: 0;
}

.answers__bar-row {
	display: grid;
	grid-template-columns: minmax(80px, 30%) minmax(0, 1fr) 2.5em;
	align-items: center;
	gap: 10px;
}

.answers__bar-label {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.answers__bar {
	height: 10px;
	background: var(--color-background-dark);
	border-radius: 5px;
	overflow: hidden;
}

.answers__bar-fill {
	display: block;
	height: 100%;
	background: var(--color-primary-element);
	border-radius: 5px;
}

.answers__bar-count {
	text-align: end;
	font-variant-numeric: tabular-nums;
}

.answers__texts {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin: 12px 0 0;
	padding: 0;
	list-style: none;
}

.answers__texts p {
	margin: 2px 0 0;
	white-space: pre-line;
	overflow-wrap: anywhere;
}

.answers__new {
	margin-inline-start: 6px;
	padding: 0 6px;
	color: var(--color-primary-element-text);
	background: var(--color-primary-element);
	border-radius: var(--border-radius-pill, 10px);
	font-size: 0.75em;
	font-weight: 600;
}

/* Answers that just arrived glow once. */
.answers__row--fresh,
.answers__text--fresh {
	animation: answers-arrive 3s ease-out;
}

@keyframes answers-arrive {
	from {
		background: var(--color-primary-element-light);
	}
}

@media (prefers-reduced-motion: reduce) {
	.answers__row--fresh,
	.answers__text--fresh {
		animation: none;
	}
}

.answers__table-wrap {
	overflow-x: auto;
	margin-top: 8px;
}

.answers__table {
	width: 100%;
	border-collapse: collapse;
}

.answers__table th,
.answers__table td {
	padding: 6px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.answers__table thead th {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.answers__table tbody th {
	font-weight: 600;
	white-space: nowrap;
}

.answers__table tbody th a {
	color: inherit;
}

.answers__table tbody th a:hover {
	text-decoration: underline;
}
</style>

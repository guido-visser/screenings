<template>
	<div class="dashboard">
		<div v-if="!data" class="dashboard__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<template v-else>
			<NcNoteCard v-if="screening.mediaStatus !== 'ready'" type="info">
				{{ t('screenings', 'The film is not ready to watch yet. Check its video in Settings.') }}
			</NcNoteCard>

			<section class="dashboard__tiles" :aria-label="t('screenings', 'Summary')">
				<div class="tile">
					<span class="tile__label">{{ t('screenings', 'Invited') }}</span>
					<span class="tile__value">{{ totals.invites }}</span>
				</div>
				<div class="tile">
					<span class="tile__label">{{ t('screenings', 'Opened the link') }}</span>
					<span class="tile__value">{{ totals.opened }}</span>
				</div>
				<div class="tile">
					<span class="tile__label">{{ t('screenings', 'Started watching') }}</span>
					<span class="tile__value">{{ totals.started }}</span>
				</div>
				<div class="tile">
					<span class="tile__label">{{ t('screenings', 'Watching now') }}</span>
					<span class="tile__value" :class="{ 'tile__value--live': watchingNow > 0 }">{{ watchingNow }}</span>
				</div>
				<div class="tile">
					<span class="tile__label">{{ t('screenings', 'Average watched') }}</span>
					<span class="tile__value">{{ totals.averagePercent === null ? '–' : Math.round(totals.averagePercent) + '%' }}</span>
				</div>
				<a v-if="totals.notes || totals.notesAsked"
					class="tile tile--link"
					:href="screeningHash(screening.id, 'notes')">
					<span class="tile__label">{{ t('screenings', 'Notes') }}</span>
					<span class="tile__value">{{ totals.notes }}</span>
				</a>
				<a v-if="totals.answered || totals.questionsAsked"
					class="tile tile--link"
					:href="screeningHash(screening.id, 'answers')">
					<span class="tile__label">{{ t('screenings', 'Answered the questions') }}</span>
					<span class="tile__value">{{ totals.answered }}<span class="tile__of"> / {{ totals.questionsAsked }}</span></span>
				</a>
			</section>

			<section class="dashboard__section">
				<h3>{{ t('screenings', 'Which parts were watched') }}</h3>
				<p v-if="!data.heatmap.max" class="dashboard__muted">
					{{ t('screenings', 'Nobody has watched yet.') }}
				</p>
				<Heatmap v-else
					:buckets="data.heatmap.buckets"
					:bucket-seconds="data.heatmap.bucketSeconds"
					:max="data.heatmap.max"
					:duration-s="data.durationS"
					:count-label="(count, max) => n('screenings', '{count} of {max} viewer watched this', '{count} of {max} viewers watched this', max, { count, max })"
					:more-label="t('screenings', 'All viewers')"
					:summary="heatmapSummary" />
			</section>

			<section class="dashboard__section">
				<h3>{{ t('screenings', 'Viewers') }}</h3>
				<p v-if="!viewers.length" class="dashboard__muted">
					{{ t('screenings', 'No invites yet. Add them in Settings, or enable the open link.') }}
				</p>
				<div v-else class="dashboard__table-wrap">
					<table class="viewers">
						<thead>
							<tr>
								<th>{{ t('screenings', 'Viewer') }}</th>
								<th>{{ t('screenings', 'Status') }}</th>
								<th class="viewers__watched">
									{{ t('screenings', 'Watched') }}
								</th>
								<th class="viewers__num">
									{{ t('screenings', 'Furthest') }}
								</th>
								<th class="viewers__num">
									{{ t('screenings', 'Opens') }}
								</th>
								<th>{{ t('screenings', 'Device and location') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="viewer in viewers"
								:key="viewer.inviteId"
								:class="{ 'viewers__row--revoked': viewer.revoked }"
								@click="open(viewer)">
								<td>
									<a :href="screeningHash(screening.id, 'viewer', viewer.inviteId)" class="viewers__name" @click.stop>
										{{ viewer.name }}
									</a>
									<span class="viewers__email">{{ viewer.email }}</span>
									<span v-if="viewer.revoked" class="viewers__badge viewers__badge--error">{{ t('screenings', 'Revoked') }}</span>
									<span v-else-if="viewer.source === 'open_link'" class="viewers__badge">{{ t('screenings', 'Open link') }}</span>
								</td>
								<td>
									<LiveBadge v-if="viewer.live" :live="viewer.live" />
									<span v-else-if="viewer.lastSeenAt" class="dashboard__muted">
										{{ t('screenings', 'Last seen {time}', { time: formatRelative(viewer.lastSeenAt) }) }}
									</span>
									<span v-else-if="viewer.opens" class="dashboard__muted">{{ t('screenings', 'Opened, not started') }}</span>
									<span v-else class="dashboard__muted">{{ t('screenings', 'Not opened yet') }}</span>
								</td>
								<td class="viewers__watched">
									<div class="viewers__bar">
										<NcProgressBar :value="viewer.percent" size="small" />
										<span class="viewers__percent">{{ Math.round(viewer.percent) }}%</span>
									</div>
								</td>
								<td class="viewers__num">
									{{ formatDuration(viewer.furthestS) }}
								</td>
								<td class="viewers__num">
									{{ viewer.opens }}
								</td>
								<td class="dashboard__muted">
									<span>{{ deviceText(viewer.device) ?? '–' }}</span>
									<span v-if="location(viewer.device)" class="viewers__location">{{ location(viewer.device) }}</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<section v-if="pending.length" class="dashboard__section">
				<h3>{{ t('screenings', 'Asked for a code, not confirmed') }}</h3>
				<p class="dashboard__muted dashboard__intro">
					{{ t('screenings', 'Visitors of the open link who requested an email code but never entered it. They become viewers once they confirm.') }}
				</p>
				<div class="dashboard__table-wrap">
					<table class="viewers viewers--static">
						<thead>
							<tr>
								<th>{{ t('screenings', 'Visitor') }}</th>
								<th class="viewers__num">
									{{ t('screenings', 'Codes sent') }}
								</th>
								<th class="viewers__num">
									{{ t('screenings', 'Wrong codes') }}
								</th>
								<th>{{ t('screenings', 'Last request') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="visitor in pending" :key="visitor.email">
								<td>
									<span class="viewers__name">{{ visitor.name || visitor.email }}</span>
									<span v-if="visitor.name" class="viewers__email">{{ visitor.email }}</span>
								</td>
								<td class="viewers__num">
									{{ visitor.codes }}
								</td>
								<td class="viewers__num">
									{{ visitor.failed }}
								</td>
								<td class="dashboard__muted">
									{{ formatRelative(Math.floor(visitor.lastAt / 1000)) }}
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
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import api, { errorMessage } from '../api.js'
import { deviceText, formatDuration, formatRelative, location } from '../format.js'
import { navigate, screeningHash } from '../router.js'
import Heatmap from './Heatmap.vue'
import LiveBadge from './LiveBadge.vue'

const props = defineProps({
	screening: { type: Object, required: true },
})

const LIVE_POLL_MS = 5000
const FULL_REFRESH_MS = 30000

const data = ref(null)
let liveTimer = null
let fullTimer = null

const totals = computed(() => data.value.totals)
const viewers = computed(() => data.value.viewers)
const pending = computed(() => data.value.pending ?? [])
const watchingNow = computed(() => viewers.value.filter((v) => v.live).length)
const heatmapSummary = computed(() => t('screenings', 'Heatmap of the film\'s timeline showing how many of the {count} viewers watched each part. The viewer table below lists each viewer\'s progress.', { count: data.value.heatmap.max }))

async function load() {
	try {
		data.value = await api.dashboard(props.screening.id)
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not load the dashboard')))
	}
}

async function refreshLive() {
	try {
		const { live } = await api.live(props.screening.id)
		for (const viewer of data.value?.viewers ?? []) {
			const now = live[viewer.inviteId] ?? null
			// Someone just stopped watching: their totals changed, reload them.
			if (viewer.live && !now) {
				load()
				return
			}
			viewer.live = now
		}
	} catch {
		// Next tick.
	}
}

function open(viewer) {
	navigate(props.screening.id, 'viewer', viewer.inviteId)
}

onMounted(async () => {
	await load()
	liveTimer = window.setInterval(refreshLive, LIVE_POLL_MS)
	fullTimer = window.setInterval(load, FULL_REFRESH_MS)
})

onBeforeUnmount(() => {
	window.clearInterval(liveTimer)
	window.clearInterval(fullTimer)
})
</script>

<style scoped>
.dashboard {
	display: flex;
	flex-direction: column;
	gap: 24px;
	margin-top: 16px;
}

/* Let the viewer table scroll instead of widening the page. */
.dashboard > * {
	min-width: 0;
}

.dashboard__loading {
	display: flex;
	justify-content: center;
	padding: 48px;
}

.dashboard__muted {
	color: var(--color-text-maxcontrast);
}

.dashboard__section h3 {
	margin: 0 0 12px;
	font-size: 1.1em;
}

.dashboard__tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 12px;
}

.tile {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.tile--link {
	color: inherit;
	text-decoration: none;
}

.tile--link:hover,
.tile--link:focus-visible {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.tile__label {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.tile__value {
	font-size: 1.8em;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
	line-height: 1.2;
}

.tile__of {
	color: var(--color-text-maxcontrast);
	font-size: 0.6em;
	font-weight: 500;
}

.tile__value--live {
	color: var(--color-success-text);
}

.dashboard__table-wrap {
	overflow-x: auto;
}

.viewers {
	width: 100%;
	border-collapse: collapse;
}

.viewers th {
	text-align: start;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
	white-space: nowrap;
}

.viewers td {
	padding: 10px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.viewers tbody tr {
	cursor: pointer;
}

.viewers tbody tr:hover {
	background: var(--color-background-hover);
}

.viewers--static tbody tr {
	cursor: default;
}

.viewers--static tbody tr:hover {
	background: none;
}

.dashboard__intro {
	margin: -4px 0 12px;
}

.viewers__row--revoked td {
	opacity: 0.6;
}

.viewers__name {
	display: block;
	font-weight: 600;
}

.viewers__email,
.viewers__location {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.viewers__badge {
	display: inline-block;
	margin-top: 4px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-size: 0.8em;
}

.viewers__badge--error {
	background: var(--color-error);
	color: var(--color-error-text, #fff);
}

.viewers__watched {
	min-width: 140px;
}

.viewers__bar {
	display: flex;
	align-items: center;
	gap: 8px;
}

.viewers__percent {
	min-width: 3em;
	text-align: end;
	font-variant-numeric: tabular-nums;
}

.viewers__num {
	text-align: end;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}
</style>

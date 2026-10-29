<template>
	<div class="viewer-detail">
		<a :href="screeningHash(screening.id, 'dashboard')" class="viewer-detail__back">
			← {{ t('screenings', 'All viewers') }}
		</a>

		<div v-if="!data" class="viewer-detail__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<template v-else>
			<header class="viewer-detail__header">
				<div>
					<h3>{{ viewer.name }}</h3>
					<span class="viewer-detail__muted">{{ viewer.source === 'public' ? t('screenings', 'Anonymous visitor of the public link') : viewer.email }}</span>
				</div>
				<span v-if="viewer.revoked" class="viewer-detail__badge viewer-detail__badge--error">{{ viewer.source === 'public' ? t('screenings', 'Blocked') : t('screenings', 'Revoked') }}</span>
				<span v-else-if="viewer.source === 'open_link'" class="viewer-detail__badge">{{ t('screenings', 'Via open link') }}</span>
				<!-- Personal invites are revoked in Settings; public visitors only appear here. -->
				<NcButton v-if="viewer.source === 'public'" class="viewer-detail__block" :disabled="blocking" @click="setBlocked(!viewer.revoked)">
					{{ viewer.revoked ? t('screenings', 'Unblock') : t('screenings', 'Block this visitor') }}
				</NcButton>
			</header>

			<section v-if="viewer.live" class="viewer-detail__live" aria-live="polite">
				<LiveBadge :live="viewer.live" />
				<span class="viewer-detail__muted">
					{{ [viewer.live.quality && viewer.live.quality !== 'source' ? viewer.live.quality : null, deviceText(viewer.device), location(viewer.device)].filter(Boolean).join(' · ') }}
				</span>
			</section>

			<section class="viewer-detail__tiles" :aria-label="t('screenings', 'Summary')">
				<div class="tile">
					<span class="tile__label">{{ t('screenings', 'Watched') }}</span>
					<span class="tile__value">{{ Math.round(viewer.percent) }}%</span>
					<span class="tile__sub">{{ formatDuration(viewer.watchedSeconds) }} / {{ formatDuration(data.durationS) }}</span>
				</div>
				<div class="tile">
					<span class="tile__label">{{ t('screenings', 'Furthest point') }}</span>
					<span class="tile__value">{{ formatDuration(viewer.furthestS) }}</span>
				</div>
				<div class="tile">
					<span class="tile__label">{{ t('screenings', 'Opened the link') }}</span>
					<span class="tile__value">{{ viewer.opens }}×</span>
					<span class="tile__sub">{{ n('screenings', '{count} viewing session', '{count} viewing sessions', viewer.sessions, { count: viewer.sessions }) }}</span>
				</div>
				<div class="tile">
					<span class="tile__label">{{ t('screenings', 'Last seen') }}</span>
					<span class="tile__value tile__value--small">{{ viewer.live ? t('screenings', 'now') : formatRelative(viewer.lastSeenAt) }}</span>
					<span v-if="viewer.firstSeenAt" class="tile__sub">{{ t('screenings', 'First: {time}', { time: formatRelative(viewer.firstSeenAt) }) }}</span>
				</div>
			</section>

			<section class="viewer-detail__section">
				<h4>{{ t('screenings', 'What {name} watched', { name: viewer.name }) }}</h4>
				<p v-if="!viewer.watchedSeconds" class="viewer-detail__muted">
					{{ t('screenings', 'Nothing played yet.') }}
				</p>
				<Heatmap v-else
					:buckets="data.heatmap.buckets"
					:bucket-seconds="data.heatmap.bucketSeconds"
					:max="data.heatmap.max"
					:duration-s="data.durationS"
					:count-label="(count) => count ? n('screenings', 'Watched in {count} session', 'Watched in {count} sessions', count, { count }) : t('screenings', 'Not watched')"
					:more-label="t('screenings', 'Re-watched')"
					:summary="t('screenings', 'Heatmap of the film\'s timeline: darker parts were watched in more sessions. The sessions table lists the details.')" />
			</section>

			<section class="viewer-detail__section">
				<h4>{{ t('screenings', 'Sessions') }}</h4>
				<p v-if="!data.sessions.length" class="viewer-detail__muted">
					{{ t('screenings', 'No viewing sessions yet.') }}
				</p>
				<div v-else class="viewer-detail__table-wrap">
					<table class="sessions">
						<thead>
							<tr>
								<th>{{ t('screenings', 'Started') }}</th>
								<th class="sessions__num">
									{{ t('screenings', 'Open for') }}
								</th>
								<th class="sessions__num">
									{{ t('screenings', 'Watched') }}
								</th>
								<th>{{ t('screenings', 'Device') }}</th>
								<th>{{ t('screenings', 'Location') }}</th>
								<th>{{ t('screenings', 'IP address') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="session in data.sessions" :key="session.id">
								<td>
									<span v-if="session.live" class="sessions__live" :title="t('screenings', 'Watching now')" />
									{{ formatDateTime(session.startedAt * 1000) }}
								</td>
								<td class="sessions__num">
									{{ formatDuration((session.lastHeartbeatAt ?? session.startedAt) - session.startedAt) }}
								</td>
								<td class="sessions__num">
									{{ formatDuration(session.watchedSeconds) }}
								</td>
								<td>
									{{ deviceText(session) ?? '–' }}
									<span v-if="session.screenW" class="viewer-detail__muted">· {{ session.screenW }}×{{ session.screenH }}</span>
									<span v-if="session.deviceType && session.deviceType !== 'desktop'" class="viewer-detail__muted">· {{ deviceLabel(session.deviceType) }}</span>
								</td>
								<td>{{ location(session) ?? '–' }}</td>
								<td class="viewer-detail__muted">
									{{ session.ip ?? '–' }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<section class="viewer-detail__section">
				<div class="viewer-detail__timeline-header">
					<h4>{{ t('screenings', 'Activity') }}</h4>
					<div class="viewer-detail__filters" role="group" :aria-label="t('screenings', 'Show')">
						<button v-for="filter in filters"
							:key="filter.id"
							class="viewer-detail__filter"
							:class="{ 'viewer-detail__filter--active': activeFilter === filter.id }"
							:aria-pressed="activeFilter === filter.id"
							@click="activeFilter = filter.id">
							{{ filter.label }}
						</button>
					</div>
				</div>
				<p v-if="!visibleEvents.length" class="viewer-detail__muted">
					{{ t('screenings', 'No activity of this kind yet.') }}
				</p>
				<ol v-else class="timeline">
					<li v-for="event in visibleEvents" :key="event.id" class="timeline__item" :data-group="eventGroup(event.type)">
						<time class="timeline__time" :datetime="new Date(event.at).toISOString()">{{ formatDateTime(event.at) }}</time>
						<span class="timeline__position">{{ event.positionS !== null ? formatDuration(event.positionS) : '' }}</span>
						<span class="timeline__text">{{ eventText(event) }}</span>
					</li>
				</ol>
				<NcButton v-if="hasMore" variant="tertiary" :disabled="loadingOlder" @click="loadOlder">
					{{ t('screenings', 'Show older activity') }}
				</NcButton>
			</section>
		</template>
	</div>
</template>

<script setup>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import api, { errorMessage } from '../api.js'
import { deviceText, EVENT_GROUPS, eventGroup, eventText, formatDateTime, formatDuration, formatRelative, location } from '../format.js'
import { screeningHash } from '../router.js'
import Heatmap from './Heatmap.vue'
import LiveBadge from './LiveBadge.vue'

const props = defineProps({
	screening: { type: Object, required: true },
	inviteId: { type: Number, required: true },
})

const POLL_MS = 5000

const data = ref(null)
// Pages beyond the first, loaded on demand and kept across polls.
const older = ref([])
const olderMore = ref(null)
const loadingOlder = ref(false)
const activeFilter = ref('all')
let timer = null

const viewer = computed(() => data.value.viewer)
const filters = [
	{ id: 'all', label: t('screenings', 'All') },
	{ id: 'playback', label: t('screenings', 'Playback') },
	{ id: 'attention', label: t('screenings', 'Attention') },
	{ id: 'access', label: t('screenings', 'Access') },
	{ id: 'feedback', label: t('screenings', 'Notes') },
	{ id: 'technical', label: t('screenings', 'Technical') },
]
const allEvents = computed(() => [...(data.value?.events.items ?? []), ...older.value])
const visibleEvents = computed(() => activeFilter.value === 'all'
	? allEvents.value
	: allEvents.value.filter((e) => EVENT_GROUPS[activeFilter.value].includes(e.type)))
const hasMore = computed(() => olderMore.value ?? data.value?.events.more)

function deviceLabel(type) {
	return { mobile: t('screenings', 'phone'), tablet: t('screenings', 'tablet') }[type] ?? type
}

const blocking = ref(false)

/** Stops (or allows again) this public visitor; playback ends within seconds. */
async function setBlocked(blocked) {
	if (blocked && !window.confirm(t('screenings', 'Block {name}? Playback stops for them within seconds. Someone who clears their cookies or uses another device comes back as a new visitor.', { name: viewer.value.name }))) {
		return
	}
	blocking.value = true
	try {
		await api.setInviteRevoked(viewer.value.inviteId, blocked)
		await load()
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not change access')))
	} finally {
		blocking.value = false
	}
}

async function load() {
	try {
		data.value = await api.viewerActivity(props.inviteId)
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not load this viewer')))
		window.clearInterval(timer)
	}
}

async function loadOlder() {
	loadingOlder.value = true
	try {
		const last = allEvents.value[allEvents.value.length - 1]
		const page = await api.viewerActivity(props.inviteId, last.id)
		older.value.push(...page.events.items)
		olderMore.value = page.events.more
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not load older activity')))
	} finally {
		loadingOlder.value = false
	}
}

onMounted(async () => {
	await load()
	timer = window.setInterval(load, POLL_MS)
})
onBeforeUnmount(() => window.clearInterval(timer))
</script>

<style scoped>
.viewer-detail {
	display: flex;
	flex-direction: column;
	gap: 20px;
	margin-top: 16px;
}

/* Flex children default to min-width: auto and would grow to the sessions
   table's width instead of letting it scroll. */
.viewer-detail > * {
	min-width: 0;
}

.viewer-detail__back {
	align-self: flex-start;
	color: var(--color-text-maxcontrast);
}

.viewer-detail__back:hover {
	color: var(--color-main-text);
}

.viewer-detail__loading {
	display: flex;
	justify-content: center;
	padding: 48px;
}

.viewer-detail__muted {
	color: var(--color-text-maxcontrast);
}

.viewer-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
}

.viewer-detail__block {
	margin-inline-start: auto;
}

.viewer-detail__header h3 {
	margin: 0;
	font-size: 1.3em;
}

.viewer-detail__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-size: 0.85em;
}

.viewer-detail__badge--error {
	background: var(--color-error);
	color: var(--color-error-text, #fff);
}

.viewer-detail__live {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-left: 4px solid var(--color-success);
	border-radius: var(--border-radius-large, 12px);
}

.viewer-detail__tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
	gap: 12px;
}

.tile {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.tile__label,
.tile__sub {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.tile__value {
	font-size: 1.8em;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
	line-height: 1.2;
}

.tile__value--small {
	font-size: 1.2em;
	line-height: 1.6;
}

.viewer-detail__section h4 {
	margin: 0 0 12px;
	font-size: 1.05em;
}

.viewer-detail__table-wrap {
	overflow-x: auto;
}

.sessions {
	width: 100%;
	border-collapse: collapse;
}

.sessions th {
	text-align: start;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
	white-space: nowrap;
}

.sessions td {
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
	white-space: nowrap;
}

.sessions__num {
	text-align: end;
	font-variant-numeric: tabular-nums;
}

.sessions__live {
	display: inline-block;
	width: 8px;
	height: 8px;
	margin-right: 6px;
	border-radius: 50%;
	background: var(--color-success);
}

.viewer-detail__timeline-header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 12px;
}

.viewer-detail__timeline-header h4 {
	margin: 0;
}

.viewer-detail__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.viewer-detail__filter {
	padding: 4px 12px;
	min-height: 32px;
	margin: 0;
	font: inherit;
	font-size: 0.9em;
	color: var(--color-main-text);
	background: transparent;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill);
	cursor: pointer;
}

.viewer-detail__filter--active {
	color: var(--color-primary-element-text);
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
}

.timeline {
	list-style: none;
	margin: 0;
	padding: 0;
}

.timeline__item {
	display: grid;
	/* minmax(0, …): long unbroken text (error codes) must wrap, not widen the page. */
	grid-template-columns: 13em 4.5em minmax(0, 1fr);
	gap: 12px;
	padding: 6px 0 6px 12px;
	border-left: 3px solid var(--color-border-dark);
}

.timeline__item[data-group="playback"] {
	border-left-color: var(--color-primary-element);
}

.timeline__item[data-group="feedback"] {
	border-left-color: var(--color-success);
}

.timeline__item[data-group="feedback"] .timeline__text {
	white-space: pre-line;
}

.timeline__item[data-group="access"] {
	border-left-color: var(--color-warning);
}

.timeline__time {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	font-variant-numeric: tabular-nums;
}

.timeline__position {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	text-align: end;
}

.timeline__text {
	overflow-wrap: anywhere;
}

@media (max-width: 700px) {
	.timeline__item {
		grid-template-columns: 4em minmax(0, 1fr);
		gap: 2px 8px;
	}

	.timeline__position {
		text-align: start;
	}

	.timeline__time {
		grid-column: 1 / -1;
	}
}
</style>

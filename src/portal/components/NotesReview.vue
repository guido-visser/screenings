<template>
	<div class="notes-review">
		<div v-if="loading" class="notes-review__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<template v-else>
			<div class="notes-review__toolbar">
				<div class="notes-review__filters" role="group" :aria-label="t('screenings', 'Show notes by')">
					<button class="notes-review__chip"
						:aria-pressed="filter === null"
						@click="filter = null">
						{{ t('screenings', 'Everyone') }}
						<span class="notes-review__chip-count">{{ notes.length }}</span>
					</button>
					<button v-for="viewer in viewers"
						:key="viewer.inviteId"
						class="notes-review__chip"
						:aria-pressed="filter === viewer.inviteId"
						:style="{ '--viewer-colour': viewer.hex }"
						@click="filter = filter === viewer.inviteId ? null : viewer.inviteId">
						<span class="notes-review__dot" aria-hidden="true" />
						{{ viewer.name }}
						<span class="notes-review__chip-count">{{ viewer.count }}</span>
					</button>
				</div>
				<div class="notes-review__options">
					<div class="notes-review__after" role="radiogroup" :aria-label="t('screenings', 'After jumping to a note')">
						<span class="notes-review__after-label">{{ t('screenings', 'After jumping:') }}</span>
						<button role="radio"
							class="notes-review__segment"
							:aria-checked="!playAfterJump"
							@click="setPlayAfterJump(false)">
							{{ t('screenings', 'Stay paused') }}
						</button>
						<button role="radio"
							class="notes-review__segment"
							:aria-checked="playAfterJump"
							@click="setPlayAfterJump(true)">
							{{ t('screenings', 'Play from note') }}
						</button>
					</div>
					<NcActions v-if="notes.length" :aria-label="t('screenings', 'Download notes')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDownload" />
						</template>
						<NcActionLink :href="api.notesExportUrl(screening.id, 'csv')" download>
							<template #icon>
								<NcIconSvgWrapper :path="mdiFileDelimitedOutline" />
							</template>
							{{ t('screenings', 'Download as spreadsheet (CSV)') }}
						</NcActionLink>
						<NcActionLink :href="api.notesExportUrl(screening.id, 'srt')" download>
							<template #icon>
								<NcIconSvgWrapper :path="mdiSubtitlesOutline" />
							</template>
							{{ t('screenings', 'Download as subtitles (SRT) for an editing timeline') }}
						</NcActionLink>
					</NcActions>
				</div>
			</div>

			<div class="notes-review__body">
				<div class="notes-review__stage">
					<ReviewPlayer v-if="preview?.ready"
						ref="player"
						:config="preview"
						:notes="visibleNotes"
						:selected="cardNote"
						@select="select"
						@close="cardNote = null"
						@time="onTime" />
					<NcNoteCard v-else type="info">
						{{ t('screenings', 'The film is not ready to watch yet. Check its video in Settings.') }}
					</NcNoteCard>
				</div>

				<section class="notes-review__list-wrap" :aria-label="t('screenings', 'Notes')">
					<NcEmptyContent v-if="!notes.length"
						:name="t('screenings', 'No notes yet')"
						:description="t('screenings', 'Viewers can leave notes when you turn notes on for their invite (Settings → Invites).')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCommentTextOutline" />
						</template>
					</NcEmptyContent>
					<ol v-else
						ref="list"
						class="notes-review__list"
						@scroll="onListScroll">
						<li v-for="note in visibleNotes"
							:key="note.id"
							:ref="(el) => setItemRef(note.id, el)"
							class="notes-review__item"
							:class="{
								'notes-review__item--selected': cardNote?.id === note.id,
								'notes-review__item--current': currentId === note.id,
							}"
							:style="{ '--viewer-colour': note.colour }">
							<button class="notes-review__item-main"
								:aria-label="t('screenings', 'Go to the note by {name} at {time}', { name: note.name, time: timecode(note.position) })"
								@click="select(note)">
								<span class="notes-review__item-head">
									<span class="notes-review__dot" aria-hidden="true" />
									<strong class="notes-review__item-name">{{ note.name }}</strong>
									<span class="notes-review__item-time">{{ timecode(note.position) }}</span>
								</span>
								<span class="notes-review__item-body">{{ note.body }}</span>
								<span class="notes-review__item-written">{{ formatRelative(note.createdAt) }}</span>
							</button>
							<NcActions class="notes-review__item-actions" :aria-label="t('screenings', 'Note actions')">
								<NcActionButton close-after-click @click="copyLinkTo(note)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiLinkVariant" />
									</template>
									{{ t('screenings', 'Copy link to this note') }}
								</NcActionButton>
								<NcActionButton close-after-click @click="remove(note)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiDeleteOutline" />
									</template>
									{{ t('screenings', 'Delete note') }}
								</NcActionButton>
							</NcActions>
						</li>
					</ol>
				</section>
			</div>
		</template>
	</div>
</template>

<script setup>
import {
	mdiCommentTextOutline,
	mdiDeleteOutline,
	mdiDownload,
	mdiFileDelimitedOutline,
	mdiLinkVariant,
	mdiSubtitlesOutline,
} from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, nextTick, onMounted, ref } from 'vue'
import api, { errorMessage } from '../api.js'
import { copyLink } from '../clipboard.js'
import { formatDuration, formatRelative } from '../format.js'
import { screeningHash } from '../router.js'
import ReviewPlayer from './ReviewPlayer.vue'

const props = defineProps({
	screening: { type: Object, required: true },
	/** Open at this note (from the URL). */
	noteId: { type: Number, default: null },
})

/** Like the viewer's player: whole seconds, never rounded up past the moment. */
const timecode = (seconds) => formatDuration(Math.floor(seconds))

/**
 * One colour per viewer, in order of their first note. Picked to stay
 * apart from each other on the dark player and in both themes.
 */
const COLOURS = ['#f5a623', '#4fc3f7', '#e57373', '#81c784', '#ba68c8', '#ffd54f', '#4db6ac', '#f06292', '#9fa8da', '#a1887f']
const AFTER_JUMP_KEY = 'screenings.notes.playAfterJump'
/** The card closes when the playhead moves this far from its note. */
const CARD_RANGE_SECONDS = 10
/** After scrolling the list by hand, it doesn't follow the film for this long. */
const MANUAL_SCROLL_MS = 4000

const loading = ref(true)
const preview = ref(null)
const notes = ref([])
const viewers = ref([])
const filter = ref(null)
const cardNote = ref(null)
const currentId = ref(null)
const playAfterJump = ref(readPlayAfterJump())
const player = ref(null)
const list = ref(null)
const itemRefs = new Map()
let manualScrollUntil = 0
let ownScroll = false

const visibleNotes = computed(() => filter.value === null
	? notes.value
	: notes.value.filter((note) => note.inviteId === filter.value))

function readPlayAfterJump() {
	try {
		return window.localStorage.getItem(AFTER_JUMP_KEY) === '1'
	} catch {
		return false
	}
}

function setPlayAfterJump(value) {
	playAfterJump.value = value
	try {
		window.localStorage.setItem(AFTER_JUMP_KEY, value ? '1' : '0')
	} catch {
		// Private mode: the choice lasts for this page only.
	}
}

function setItemRef(id, el) {
	if (el) {
		itemRefs.set(id, el)
	} else {
		itemRefs.delete(id)
	}
}

/** Scrolls the list only, never the page around it. */
function scrollToNote(id) {
	const el = itemRefs.get(id)
	const box = list.value
	if (!el || !box) {
		return
	}
	let top = null
	if (el.offsetTop < box.scrollTop) {
		top = el.offsetTop
	} else if (el.offsetTop + el.offsetHeight > box.scrollTop + box.clientHeight) {
		top = el.offsetTop + el.offsetHeight - box.clientHeight
	}
	if (top === null) {
		return
	}
	ownScroll = true
	box.scrollTo({ top, behavior: 'smooth' })
	window.setTimeout(() => { ownScroll = false }, 600)
}

function onListScroll() {
	if (!ownScroll) {
		manualScrollUntil = Date.now() + MANUAL_SCROLL_MS
	}
}

/**
 * A marker or a list item: jump there, show the card, keep the URL.
 *
 * @param {object} note the note
 * @param {boolean} play start playing there (default: the owner's choice)
 */
function select(note, play = playAfterJump.value) {
	cardNote.value = note
	currentId.value = note.id
	player.value?.seekTo(note.position, play)
	scrollToNote(note.id)
	window.history.replaceState(null, '', screeningHash(props.screening.id, 'notes') + '/' + note.id)
}

/** Follows the playhead: highlight the latest note passed, close a far card. */
function onTime(seconds) {
	if (cardNote.value && Math.abs(seconds - cardNote.value.position) > CARD_RANGE_SECONDS) {
		cardNote.value = null
	}
	let latest = null
	for (const note of visibleNotes.value) {
		if (note.position <= seconds + 0.5) {
			latest = note
		} else {
			break
		}
	}
	if (latest && latest.id !== currentId.value) {
		currentId.value = latest.id
		if (Date.now() > manualScrollUntil) {
			scrollToNote(latest.id)
		}
	}
}

function copyLinkTo(note) {
	copyLink(window.location.origin + window.location.pathname + screeningHash(props.screening.id, 'notes') + '/' + note.id)
}

async function remove(note) {
	if (!window.confirm(t('screenings', 'Delete the note by {name} at {time}? This can\'t be undone.', { name: note.name, time: timecode(note.position) }))) {
		return
	}
	try {
		await api.deleteNote(note.id)
		notes.value = notes.value.filter((n) => n.id !== note.id)
		const viewer = viewers.value.find((v) => v.inviteId === note.inviteId)
		if (viewer) {
			viewer.count--
		}
		viewers.value = viewers.value.filter((v) => v.count > 0)
		if (filter.value !== null && !viewers.value.some((v) => v.inviteId === filter.value)) {
			filter.value = null
		}
		if (cardNote.value?.id === note.id) {
			cardNote.value = null
		}
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not delete the note')))
	}
}

onMounted(async () => {
	try {
		const [config, data] = await Promise.all([api.preview(props.screening.id), api.notes(props.screening.id)])
		preview.value = config
		viewers.value = data.viewers.map((v) => ({ ...v, hex: COLOURS[v.colour % COLOURS.length] }))
		const byInvite = new Map(viewers.value.map((v) => [v.inviteId, v]))
		notes.value = data.notes.map((note) => ({
			...note,
			name: byInvite.get(note.inviteId)?.name ?? '?',
			colour: byInvite.get(note.inviteId)?.hex ?? '#fff',
		}))
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not load the notes')))
	} finally {
		loading.value = false
	}
	if (props.noteId !== null) {
		const note = notes.value.find((n) => n.id === props.noteId)
		if (note) {
			// The player keeps the jump until the film's metadata is in. A link
			// opens paused: browsers block playback nobody clicked for.
			await nextTick()
			select(note, false)
		}
	}
})
</script>

<style scoped>
.notes-review {
	container: notes-review / inline-size;
}

.notes-review__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.notes-review__toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 8px 16px;
	margin-bottom: 12px;
}

.notes-review__filters,
.notes-review__options,
.notes-review__after {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 6px;
}

.notes-review__dot {
	display: inline-block;
	flex: none;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--viewer-colour);
	box-shadow: 0 0 0 1px var(--color-border-dark);
}

.notes-review .notes-review__chip,
.notes-review .notes-review__segment {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	min-height: 34px;
	margin: 0;
	padding: 4px 12px;
	font: inherit;
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill, 17px);
	cursor: pointer;
}

.notes-review .notes-review__chip:hover,
.notes-review .notes-review__segment:hover {
	background: var(--color-background-hover);
}

.notes-review .notes-review__chip[aria-pressed="true"],
.notes-review .notes-review__segment[aria-checked="true"] {
	color: var(--color-primary-element-text);
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
}

.notes-review__chip-count {
	font-variant-numeric: tabular-nums;
	opacity: 0.8;
}

.notes-review__after-label {
	color: var(--color-text-maxcontrast);
}

.notes-review__body {
	display: grid;
	grid-template-columns: minmax(0, 2fr) minmax(260px, 1fr);
	gap: 16px;
	align-items: start;
}

.notes-review__list-wrap {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	overflow: hidden;
}

/* As tall as the player next to it (16:9 of two thirds of the width). */
.notes-review__list {
	position: relative;
	max-height: max(320px, calc((100cqi - 16px) * 2 / 3 * 9 / 16));
	margin: 0;
	padding: 0;
	list-style: none;
	overflow-y: auto;
}

.notes-review__item {
	position: relative;
	display: flex;
	align-items: flex-start;
	border-bottom: 1px solid var(--color-border);
	border-left: 4px solid var(--viewer-colour);
}

.notes-review__item:last-child {
	border-bottom: 0;
}

.notes-review__item--current {
	background: var(--color-background-hover);
}

.notes-review__item--selected {
	background: var(--color-primary-element-light);
}

.notes-review .notes-review__item-main {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
	min-width: 0;
	min-height: 0;
	margin: 0;
	padding: 10px 4px 10px 12px;
	text-align: start;
	font: inherit;
	color: var(--color-main-text);
	background: transparent;
	border: 0;
	border-radius: 0;
	cursor: pointer;
}

.notes-review__item-head {
	display: flex;
	align-items: center;
	gap: 8px;
}

.notes-review__item-name {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.notes-review__item-time {
	margin-left: auto;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	font-weight: 600;
}

.notes-review__item-body {
	white-space: pre-line;
	overflow-wrap: anywhere;
	line-height: 1.45;
}

.notes-review__item-written {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.notes-review__item-actions {
	margin: 6px 4px 0 0;
}

@container notes-review (max-width: 860px) {
	.notes-review__body {
		grid-template-columns: minmax(0, 1fr);
	}

	.notes-review__list {
		max-height: 60vh;
	}
}
</style>

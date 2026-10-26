<template>
	<section class="notes" :aria-labelledby="'notes-title'">
		<h2 id="notes-title" class="notes__title">
			{{ t('screenings', 'My notes') }}
			<span v-if="notes.items.length" class="notes__count">{{ notes.items.length }}</span>
		</h2>
		<p class="notes__intro">
			{{ t('screenings', '{owner} would like to hear what you think. Press N or the note button in the player to write down a thought at that moment. Only {owner} sees your notes.', { owner }) }}
		</p>
		<p v-if="loadError" class="notes__error" role="alert">
			{{ loadError }}
		</p>

		<ol v-if="notes.items.length" class="notes__list">
			<li v-for="note in notes.items" :key="note.id" class="notes__item">
				<button class="notes__time"
					:aria-label="t('screenings', 'Go to {time}', { time: formatTime(note.position) })"
					@click="emit('seek', note.position)">
					{{ formatTime(note.position) }}
				</button>
				<form v-if="editingId === note.id" class="notes__edit" @submit.prevent="save(note)">
					<textarea ref="editInput"
						v-model="editText"
						rows="3"
						maxlength="2000"
						:aria-label="t('screenings', 'Note at {time}', { time: formatTime(note.position) })"
						@keydown.escape.prevent="editingId = null" />
					<p v-if="error" class="notes__error" role="alert">
						{{ error }}
					</p>
					<div class="notes__actions">
						<button type="button" class="notes__button" @click="editingId = null">
							{{ t('screenings', 'Cancel') }}
						</button>
						<button type="submit" class="notes__button notes__button--primary" :disabled="busy || !editText.trim()">
							{{ t('screenings', 'Save') }}
						</button>
					</div>
				</form>
				<template v-else>
					<p class="notes__body">
						{{ note.body }}
					</p>
					<div class="notes__actions">
						<button class="notes__button" :disabled="busy" @click="edit(note)">
							{{ t('screenings', 'Edit') }}
						</button>
						<button class="notes__button" :disabled="busy" @click="remove(note)">
							{{ t('screenings', 'Delete') }}
						</button>
					</div>
				</template>
			</li>
		</ol>
	</section>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import { nextTick, onMounted, ref } from 'vue'
import { formatTime } from '../playback.js'

const props = defineProps({
	/** The store from notes.js. */
	notes: { type: Object, required: true },
	owner: { type: String, required: true },
})
const emit = defineEmits(['seek'])

const editingId = ref(null)
const editText = ref('')
const editInput = ref(null)
const busy = ref(false)
const error = ref(null)
const loadError = ref(null)

async function edit(note) {
	editingId.value = note.id
	editText.value = note.body
	error.value = null
	await nextTick()
	editInput.value?.[0]?.focus()
}

async function save(note) {
	busy.value = true
	error.value = null
	try {
		await props.notes.update(note.id, editText.value)
		editingId.value = null
	} catch (e) {
		error.value = e.message
	} finally {
		busy.value = false
	}
}

async function remove(note) {
	if (!window.confirm(t('screenings', 'Delete your note at {time}?', { time: formatTime(note.position) }))) {
		return
	}
	busy.value = true
	try {
		await props.notes.remove(note.id)
	} catch (e) {
		window.alert(e.message)
	} finally {
		busy.value = false
	}
}

onMounted(async () => {
	try {
		await props.notes.load()
	} catch (e) {
		loadError.value = e.message
	}
})
</script>

<template>
	<div class="mail-editor">
		<div v-if="!loaded" class="mail-editor__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<template v-else>
			<NcNoteCard :type="sourceNote.type">
				<p>{{ sourceNote.text }}</p>
			</NcNoteCard>

			<div class="mail-editor__layout">
				<section class="mail-editor__edit" :aria-label="t('screenings', 'Design')">
					<NcTextField ref="subjectField"
						v-model="subject"
						:label="t('screenings', 'Subject')"
						:error="field === 'subject'"
						@focus="lastField = 'subject'" />

					<div class="mail-editor__variables">
						<span class="mail-editor__hint">{{ t('screenings', 'Insert:') }}</span>
						<button v-for="variable in variables"
							:key="variable.name"
							type="button"
							class="mail-editor__chip"
							:title="variable.description"
							@click="insert(variable.name)">
							{{ chipText(variable.name) }}
						</button>
					</div>

					<label class="mail-editor__html-label" for="mail-editor-html">{{ t('screenings', 'Email (HTML)') }}</label>
					<textarea id="mail-editor-html"
						ref="htmlField"
						v-model="html"
						class="mail-editor__html"
						:class="{ 'mail-editor__html--error': field === 'html' }"
						spellcheck="false"
						autocomplete="off"
						@focus="lastField = 'html'"
						@keydown.tab.prevent="insertText('\t')" />
					<details class="mail-editor__help">
						<summary>{{ t('screenings', 'How placeholders work') }}</summary>
						<p v-for="(text, index) in helpTexts" :key="index">
							{{ text }}
						</p>
					</details>

					<div class="mail-editor__actions">
						<NcButton variant="primary" :disabled="!dirty || saving" @click="save">
							{{ saving ? t('screenings', 'Saving…') : t('screenings', 'Save') }}
						</NcButton>
						<NcButton :disabled="testing" @click="sendTest">
							<template #icon>
								<NcIconSvgWrapper :path="mdiEmailFastOutline" />
							</template>
							{{ t('screenings', 'Send a test to me') }}
						</NcButton>
						<NcButton variant="tertiary" @click="loadBuiltin">
							{{ t('screenings', 'Start from the built-in design') }}
						</NcButton>
						<NcButton v-if="canReset" variant="tertiary" @click="reset">
							{{ screeningId === null ? t('screenings', 'Remove my default design') : t('screenings', 'Use my default design again') }}
						</NcButton>
						<span v-if="dirty" class="mail-editor__hint">{{ t('screenings', 'Unsaved changes') }}</span>
					</div>
				</section>

				<section class="mail-editor__preview" :aria-label="t('screenings', 'Preview')">
					<div class="mail-editor__preview-head">
						<div class="mail-editor__modes" role="group" :aria-label="t('screenings', 'Preview')">
							<button type="button"
								:class="{ active: previewMode === 'html' }"
								:aria-pressed="previewMode === 'html'"
								@click="previewMode = 'html'">
								{{ t('screenings', 'Preview') }}
							</button>
							<button type="button"
								:class="{ active: previewMode === 'text' }"
								:aria-pressed="previewMode === 'text'"
								@click="previewMode = 'text'">
								{{ t('screenings', 'Plain text') }}
							</button>
						</div>
						<NcLoadingIcon v-if="previewing" :size="20" />
					</div>
					<NcNoteCard v-if="previewError" type="error">
						<p>{{ previewError }}</p>
					</NcNoteCard>
					<NcNoteCard v-else-if="unknownText" type="warning">
						<p>{{ unknownText }}</p>
					</NcNoteCard>
					<p v-if="preview" class="mail-editor__subject">
						<span class="mail-editor__hint">{{ t('screenings', 'Subject:') }}</span> {{ preview.subject }}
					</p>
					<!-- sandbox without allow-scripts/allow-same-origin: the design can't run
						code or reach this page, whatever it contains. -->
					<iframe v-if="preview && previewMode === 'html'"
						class="mail-editor__frame"
						sandbox=""
						referrerpolicy="no-referrer"
						:title="t('screenings', 'Email preview')"
						:srcdoc="preview.html" />
					<pre v-else-if="preview" class="mail-editor__text">{{ preview.text }}</pre>
					<p class="mail-editor__hint">
						{{ screeningId === null
							? t('screenings', 'Filled with example details. Each invitation uses the screening\'s and the viewer\'s own.')
							: t('screenings', 'Filled with this screening\'s details and an example viewer.') }}
					</p>
				</section>
			</div>
		</template>
	</div>
</template>

<script setup>
import { mdiEmailFastOutline } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import api, { errorMessage } from '../api.js'

const props = defineProps({
	/** null: the owner's default design */
	screeningId: { type: Number, default: null },
})

// Placeholders that only make sense as a section.
const SECTION_ONLY = ['password']
const PREVIEW_DELAY_MS = 400

const loaded = ref(false)
const subject = ref('')
const html = ref('')
const source = ref('builtin')
const saved = ref({ subject: '', html: '' })
const builtin = ref({ subject: '', html: '' })
const variables = ref([])
const preview = ref(null)
const previewError = ref('')
const previewMode = ref('html')
const previewing = ref(false)
const saving = ref(false)
const testing = ref(false)
const field = ref(null)
const lastField = ref('html')
const htmlField = ref(null)
const subjectField = ref(null)
let previewTimer = null
let previewSeq = 0

// Kept out of the template: Vue's own {{ }} would read these placeholders.
const OPEN = '{{'
const CLOSE = '}}'
const helpTexts = [
	t('screenings', '{placeholder} is replaced by the viewer\'s name, and so on; hover a placeholder above to see what it contains. Values are inserted as text, so they can\'t break your HTML.', { placeholder: OPEN + 'name' + CLOSE }),
	t('screenings', 'Show something only when a value is set: {example}. Only when it is empty: {emptyExample}.', {
		example: `${OPEN}#expires${CLOSE}The link works until ${OPEN}expires${CLOSE}.${OPEN}/expires${CLOSE}`,
		emptyExample: `${OPEN}^message${CLOSE}…${OPEN}/message${CLOSE}`,
	}),
	t('screenings', 'The email must contain {placeholder}. Use inline styles and tables for the layout: many mail apps ignore style sheets. A plain-text version is made automatically for mail apps that don\'t show HTML.', { placeholder: OPEN + 'link' + CLOSE }),
]
const unknownText = computed(() => preview.value?.unknown?.length
	? t('screenings', 'Unknown placeholders, shown as nothing: {names}', { names: preview.value.unknown.map((name) => OPEN + name + CLOSE).join(', ') })
	: '')

const dirty = computed(() => subject.value !== saved.value.subject || html.value !== saved.value.html)
const canReset = computed(() => props.screeningId === null ? source.value === 'default' : source.value === 'screening')

const sourceNote = computed(() => {
	if (props.screeningId === null) {
		return source.value === 'default'
			? { type: 'info', text: t('screenings', 'This is your default invitation email, used by every screening that has no design of its own.') }
			: { type: 'info', text: t('screenings', 'You are using the built-in design. Saving makes this your default for all screenings.') }
	}
	if (source.value === 'screening') {
		return { type: 'info', text: t('screenings', 'This screening has its own invitation email.') }
	}
	return {
		type: 'info',
		text: source.value === 'default'
			? t('screenings', 'This screening uses your default design. Saving here gives it a design of its own.')
			: t('screenings', 'This screening uses the built-in design. Saving here gives it a design of its own; to change it for all screenings, use "Invitation email" in the navigation.'),
	}
})

function apply(design) {
	subject.value = design.subject
	html.value = design.html
	source.value = design.source
	saved.value = { subject: design.subject, html: design.html }
}

async function load() {
	try {
		const data = await api.getMailTemplate(props.screeningId)
		builtin.value = data.builtin
		variables.value = data.variables
		apply(data)
		loaded.value = true
		await refreshPreview()
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not load the invitation email')))
	}
}

async function refreshPreview() {
	const seq = ++previewSeq
	previewing.value = true
	try {
		const result = await api.previewMailTemplate(props.screeningId, subject.value, html.value)
		if (seq === previewSeq) {
			preview.value = result
			previewError.value = ''
		}
	} catch (error) {
		if (seq === previewSeq) {
			// Keep the last good preview; explain what's wrong.
			previewError.value = errorMessage(error, t('screenings', 'The preview could not be made'))
		}
	} finally {
		if (seq === previewSeq) {
			previewing.value = false
		}
	}
}

watch([subject, html], () => {
	field.value = null
	window.clearTimeout(previewTimer)
	previewTimer = window.setTimeout(refreshPreview, PREVIEW_DELAY_MS)
})

function chipText(name) {
	return SECTION_ONLY.includes(name) ? `${OPEN}#${name}${CLOSE}…` : OPEN + name + CLOSE
}

function insert(name) {
	insertText(SECTION_ONLY.includes(name) ? `${OPEN}#${name}${CLOSE}${OPEN}/${name}${CLOSE}` : OPEN + name + CLOSE)
}

/** Inserts at the cursor of the field last used (the HTML by default). */
function insertText(text) {
	const el = lastField.value === 'subject'
		? subjectField.value?.$el?.querySelector('input')
		: htmlField.value
	const target = lastField.value === 'subject' ? subject : html
	if (!el) {
		target.value += text
		return
	}
	const start = el.selectionStart ?? target.value.length
	const end = el.selectionEnd ?? start
	target.value = target.value.slice(0, start) + text + target.value.slice(end)
	nextTick(() => {
		el.focus()
		el.setSelectionRange(start + text.length, start + text.length)
	})
}

async function save() {
	saving.value = true
	try {
		apply(await api.saveMailTemplate(props.screeningId, subject.value, html.value))
		showSuccess(t('screenings', 'Invitation email saved'))
	} catch (error) {
		field.value = error?.response?.data?.field ?? null
		showError(errorMessage(error, t('screenings', 'Could not save the invitation email')))
	} finally {
		saving.value = false
	}
}

async function reset() {
	try {
		apply(await api.resetMailTemplate(props.screeningId))
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not reset the invitation email')))
	}
}

function loadBuiltin() {
	if (dirty.value && !window.confirm(t('screenings', 'Replace your unsaved changes with the built-in design?'))) {
		return
	}
	subject.value = builtin.value.subject
	html.value = builtin.value.html
}

async function sendTest() {
	testing.value = true
	try {
		const { sentTo } = await api.sendTestMail(props.screeningId, subject.value, html.value)
		showSuccess(t('screenings', 'Test email sent to {email}', { email: sentTo }))
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not send the test email')))
	} finally {
		testing.value = false
	}
}

function onBeforeUnload(event) {
	if (dirty.value) {
		event.preventDefault()
	}
}

onMounted(() => {
	load()
	window.addEventListener('beforeunload', onBeforeUnload)
})
onBeforeUnmount(() => {
	window.clearTimeout(previewTimer)
	window.removeEventListener('beforeunload', onBeforeUnload)
})
</script>

<style scoped>
.mail-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: 16px;
}

.mail-editor__loading {
	display: flex;
	justify-content: center;
	padding: 48px;
}

.mail-editor__layout {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 24px;
	align-items: start;
}

@media (max-width: 1100px) {
	.mail-editor__layout {
		grid-template-columns: minmax(0, 1fr);
	}
}

.mail-editor__edit,
.mail-editor__preview {
	display: flex;
	flex-direction: column;
	gap: 10px;
	min-width: 0;
}

.mail-editor__variables {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
}

.mail-editor__chip {
	margin: 0;
	padding: 2px 8px;
	min-height: 0;
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.85em;
	color: var(--color-main-text);
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	cursor: pointer;
}

.mail-editor__chip:hover {
	background: var(--color-background-hover);
}

.mail-editor__html-label {
	font-weight: 600;
}

.mail-editor__html {
	width: 100%;
	min-height: 480px;
	margin: 0;
	padding: 10px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
	line-height: 1.45;
	tab-size: 4;
	white-space: pre;
	resize: vertical;
}

.mail-editor__html--error {
	border-color: var(--color-error);
}

.mail-editor__help {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.mail-editor__help p {
	margin: 6px 0;
}

.mail-editor__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.mail-editor__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.mail-editor__preview-head {
	display: flex;
	align-items: center;
	gap: 8px;
}

.mail-editor__modes {
	display: inline-flex;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element, 8px);
	overflow: hidden;
}

.mail-editor__modes button {
	margin: 0;
	padding: 6px 12px;
	min-height: 0;
	color: var(--color-main-text);
	background: none;
	border: 0;
	border-radius: 0;
}

.mail-editor__modes button.active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.mail-editor__subject {
	margin: 0;
	overflow-wrap: anywhere;
}

.mail-editor__frame {
	width: 100%;
	height: 640px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: #fff;
}

.mail-editor__text {
	margin: 0;
	min-height: 320px;
	padding: 12px;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	font-size: 13px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large, 12px);
}
</style>

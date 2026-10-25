<template>
	<section class="invites">
		<h3>{{ t('screenings', 'Invites') }}</h3>

		<form class="invites__add" @submit.prevent="add">
			<div class="invites__fields">
				<NcTextField v-model="name" :label="t('screenings', 'Name')" />
				<NcTextField v-model="email" type="email" :label="t('screenings', 'Email')" />
			</div>
			<NcCheckboxRadioSwitch v-model="sendEmail">
				{{ t('screenings', 'Email the invitation now') }}
			</NcCheckboxRadioSwitch>
			<NcTextArea v-if="sendEmail"
				v-model="message"
				:label="t('screenings', 'Personal message (optional)')"
				resize="vertical"
				maxlength="2000" />
			<div class="invites__submit">
				<NcButton type="submit" variant="primary" :disabled="!name.trim() || !email.trim() || adding">
					<template #icon>
						<NcIconSvgWrapper :path="sendEmail ? mdiEmailFastOutline : mdiAccountPlusOutline" />
					</template>
					{{ sendEmail ? t('screenings', 'Add and send invitation') : t('screenings', 'Add invite') }}
				</NcButton>
				<span v-if="hasPassword" class="invites__hint">
					{{ t('screenings', 'The password is not included in the email; share it separately.') }}
				</span>
			</div>
		</form>

		<ul v-if="items.length" class="invites__list">
			<li v-for="invite in items"
				:key="invite.id"
				class="invites__item"
				:class="{ 'invites__item--revoked': invite.revokedAt }">
				<div class="invites__who">
					<strong>{{ invite.name }}</strong>
					<span>{{ invite.email }}</span>
					<span class="invites__status">{{ statusText(invite) }}</span>
				</div>
				<span v-if="invite.source === 'open_link'" class="invites__badge">
					{{ t('screenings', 'Via open link') }}
				</span>
				<span v-if="invite.revokedAt" class="invites__badge invites__badge--error">
					{{ t('screenings', 'Revoked') }}
				</span>
				<NcButton variant="tertiary" :aria-label="t('screenings', 'Copy link for {name}', { name: invite.name })" @click="copyLink(publicLink(invite.token))">
					<template #icon>
						<NcIconSvgWrapper :path="mdiContentCopy" />
					</template>
				</NcButton>
				<NcActions>
					<NcActionButton v-if="!invite.revokedAt" close-after-click @click="send(invite)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiEmailOutline" />
						</template>
						{{ invite.invitedAt ? t('screenings', 'Resend invitation') : t('screenings', 'Send invitation') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="setRevoked(invite, !invite.revokedAt)">
						<template #icon>
							<NcIconSvgWrapper :path="invite.revokedAt ? mdiLinkVariant : mdiLinkVariantOff" />
						</template>
						{{ invite.revokedAt ? t('screenings', 'Restore access') : t('screenings', 'Revoke access') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="remove(invite)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDeleteOutline" />
						</template>
						{{ t('screenings', 'Delete invite and its data') }}
					</NcActionButton>
				</NcActions>
			</li>
		</ul>
		<p v-else class="invites__empty">
			{{ t('screenings', 'No invites yet.') }}
		</p>
	</section>
</template>

<script setup>
import {
	mdiAccountPlusOutline,
	mdiContentCopy,
	mdiDeleteOutline,
	mdiEmailFastOutline,
	mdiEmailOutline,
	mdiLinkVariant,
	mdiLinkVariantOff,
} from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { formatRelativeTime, t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ref, watch } from 'vue'
import api, { errorMessage, publicLink } from '../api.js'
import { copyLink } from '../clipboard.js'

const props = defineProps({
	screeningId: { type: Number, required: true },
	invites: { type: Array, default: () => [] },
	hasPassword: { type: Boolean, default: false },
})
const emit = defineEmits(['count'])

const items = ref([...props.invites])
const name = ref('')
const email = ref('')
const sendEmail = ref(true)
const message = ref('')
const adding = ref(false)

watch(() => items.value.length, (count) => emit('count', count))

function statusText(invite) {
	if (invite.source === 'open_link') {
		return t('screenings', 'Confirmed their email {time}', { time: formatRelativeTime((invite.emailVerifiedAt ?? invite.createdAt) * 1000) })
	}
	if (invite.invitedAt) {
		return t('screenings', 'Invitation sent {time}', { time: formatRelativeTime(invite.invitedAt * 1000) })
	}
	return t('screenings', 'Invitation not sent; copy the link or send it')
}

async function add() {
	adding.value = true
	try {
		const invite = await api.createInvite(props.screeningId, name.value, email.value, sendEmail.value, message.value)
		items.value.unshift(invite)
		if (invite.mailError) {
			showError(t('screenings', 'The invite was added, but the email failed: {error}', { error: invite.mailError }))
		} else if (sendEmail.value) {
			showSuccess(t('screenings', 'Invitation sent to {email}', { email: invite.email }))
		}
		name.value = ''
		email.value = ''
		message.value = ''
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not add the invite')))
	} finally {
		adding.value = false
	}
}

async function send(invite) {
	try {
		const saved = await api.sendInvite(invite.id)
		items.value = items.value.map((i) => i.id === saved.id ? saved : i)
		showSuccess(t('screenings', 'Invitation sent to {email}', { email: saved.email }))
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not send the invitation')))
	}
}

async function setRevoked(invite, revoked) {
	try {
		const saved = await api.setInviteRevoked(invite.id, revoked)
		items.value = items.value.map((i) => i.id === saved.id ? saved : i)
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not change access')))
	}
}

async function remove(invite) {
	if (!window.confirm(t('screenings', 'Delete the invite for {name} with all their viewing data?', { name: invite.name }))) {
		return
	}
	try {
		await api.deleteInvite(invite.id)
		items.value = items.value.filter((i) => i.id !== invite.id)
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not delete the invite')))
	}
}
</script>

<style scoped>
.invites {
	margin-top: 32px;
}

.invites h3 {
	margin: 0 0 12px;
	font-size: 1.1em;
}

.invites__add {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.invites__fields {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.invites__submit {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.invites__hint,
.invites__empty,
.invites__status {
	color: var(--color-text-maxcontrast);
}

.invites__status {
	font-size: 0.9em;
}

.invites__list {
	display: flex;
	flex-direction: column;
	margin-top: 16px;
}

.invites__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.invites__item--revoked .invites__who {
	opacity: 0.6;
}

.invites__who {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-width: 0;
}

.invites__who span {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.invites__badge {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-size: 0.85em;
	white-space: nowrap;
}

.invites__badge--error {
	background: var(--color-error);
	color: var(--color-error-text, #fff);
}

@media (max-width: 600px) {
	.invites__fields {
		grid-template-columns: 1fr;
	}
}
</style>

<template>
	<section class="invites">
		<h3>{{ t('screenings', 'Invites') }}</h3>

		<form class="invites__add" @submit.prevent="add">
			<NcTextField v-model="name" :label="t('screenings', 'Name')" />
			<NcTextField v-model="email" type="email" :label="t('screenings', 'Email')" />
			<NcButton type="submit" variant="primary" :disabled="!name.trim() || !email.trim() || adding">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAccountPlusOutline" />
				</template>
				{{ t('screenings', 'Add invite') }}
			</NcButton>
		</form>
		<p class="invites__hint">
			{{ t('screenings', 'Each invite gets its own personal link. Sending invitations by email arrives in a later version; copy the link for now.') }}
		</p>

		<ul v-if="items.length" class="invites__list">
			<li v-for="invite in items"
				:key="invite.id"
				class="invites__item"
				:class="{ 'invites__item--revoked': invite.revokedAt }">
				<div class="invites__who">
					<strong>{{ invite.name }}</strong>
					<span>{{ invite.email }}</span>
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
import { mdiAccountPlusOutline, mdiContentCopy, mdiDeleteOutline, mdiLinkVariant, mdiLinkVariantOff } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ref, watch } from 'vue'
import api, { errorMessage, publicLink } from '../api.js'
import { copyLink } from '../clipboard.js'

const props = defineProps({
	screeningId: { type: Number, required: true },
	invites: { type: Array, default: () => [] },
})
const emit = defineEmits(['count'])

const items = ref([...props.invites])
const name = ref('')
const email = ref('')
const adding = ref(false)

watch(() => items.value.length, (count) => emit('count', count))

async function add() {
	adding.value = true
	try {
		items.value.unshift(await api.createInvite(props.screeningId, name.value, email.value))
		name.value = ''
		email.value = ''
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not add the invite')))
	} finally {
		adding.value = false
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
	display: grid;
	grid-template-columns: 1fr 1fr auto;
	align-items: end;
	gap: 8px;
}

.invites__hint,
.invites__empty {
	color: var(--color-text-maxcontrast);
}

.invites__list {
	display: flex;
	flex-direction: column;
	margin-top: 12px;
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
	.invites__add {
		grid-template-columns: 1fr;
	}
}
</style>

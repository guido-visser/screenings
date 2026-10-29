<template>
	<header class="screening-header" :class="{ 'screening-header--wide': wide }">
		<p class="screening-header__kicker">
			{{ viewer?.public
				? t('screenings', 'A screening from {owner}', { owner: screening.owner })
				: t('screenings', 'Private screening from {owner}', { owner: screening.owner }) }}
		</p>
		<h1>{{ screening.title }}</h1>
		<p v-if="viewer && !viewer.public" class="screening-header__viewer">
			{{ t('screenings', 'For {name}', { name: viewer.name }) }}
		</p>
		<p v-if="screening.description" class="screening-header__description">
			{{ screening.description }}
		</p>
	</header>
</template>

<script setup>
import { t } from '@nextcloud/l10n'

defineProps({
	screening: { type: Object, required: true },
	viewer: { type: Object, default: null },
	wide: { type: Boolean, default: false },
})
</script>

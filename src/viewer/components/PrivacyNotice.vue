<template>
	<p class="privacy-notice">
		{{ t('screenings', 'This screening records your viewing activity (when you open this page and how you watch: playing, pausing, seeking, subtitles and leaving) and shares it with {owner}.', { owner }) }}
	</p>
</template>

<script setup>
import { t } from '@nextcloud/l10n'

defineProps({
	owner: { type: String, required: true },
})
</script>

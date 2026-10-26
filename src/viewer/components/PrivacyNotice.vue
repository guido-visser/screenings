<template>
	<p class="privacy-notice">
		{{ text }}
	</p>
</template>

<script setup>
defineProps({
	/** The owner's notice for this screening, already resolved on the server. */
	text: { type: String, required: true },
})
</script>

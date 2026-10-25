<template>
	<NcContent app-name="screenings">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationNew :text="t('screenings', 'New screening')" disabled />
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<NcEmptyContent :name="t('screenings', 'No screenings yet')"
				:description="t('screenings', 'Create a screening to start sending tracked links.')" />
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
</script>

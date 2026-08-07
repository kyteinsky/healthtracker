<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent appName="healthtracker">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					:name="t('healthtracker', 'Documents')"
					:to="{ name: 'documents' }">
					<template #icon>
						<FileDocumentOutline :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('healthtracker', 'Results')"
					:to="{ name: 'results' }">
					<template #icon>
						<TableArrowRight :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('healthtracker', 'Trends')"
					:to="{ name: 'trends' }">
					<template #icon>
						<ChartLine :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<router-view />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import TableArrowRight from 'vue-material-design-icons/TableArrowRight.vue'
import { store } from './store.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationItem,
		FileDocumentOutline,
		TableArrowRight,
		ChartLine,
	},

	setup() {
		return { store }
	},

	mounted() {
		store.loadSession()
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
// Everything else is themed by @nextcloud/vue; nothing custom needed at the shell level.
</style>

<style lang="scss">
// The page shell and table styles every view builds on. Global on purpose —
// see the file header.
@use './styles/page.scss';

// Keep the page titles clear of the floating app-navigation toggle button,
// which overlays the top-left corner of the content area (applies to every view).
.app-healthtracker .page__header {
	padding-inline-start: calc(var(--default-clickable-area, 44px) + var(--app-navigation-padding, 4px) * 2);
}
</style>

<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page page--narrow page--roomy">
		<header class="page__header page__header--center">
			<h2 class="page__title">
				{{ t('healthtracker', 'Results') }}
			</h2>
		</header>

		<section class="results__controls">
			<NcSelect
				v-model="testName"
				:options="testNames"
				:placeholder="t('healthtracker', 'All tests')"
				:clearable="true"
				:searchable="false"
				class="results__select" />
			<label class="results__date">
				<span>{{ t('healthtracker', 'From') }}</span>
				<input type="date" v-model="from">
			</label>
			<label class="results__date">
				<span>{{ t('healthtracker', 'To') }}</span>
				<input type="date" v-model="to">
			</label>
		</section>

		<section class="results">
			<SkeletonList v-if="loading && !results.length" :rows="5" />
			<template v-else-if="results.length">
				<div class="table-wrap">
					<table class="tbl">
						<thead>
							<tr>
								<th>{{ t('healthtracker', 'Test date') }}</th>
								<th>{{ t('healthtracker', 'Test name') }}</th>
								<th>{{ t('healthtracker', 'Value') }}</th>
								<th>{{ t('healthtracker', 'Ref range') }}</th>
								<th>{{ t('healthtracker', 'Severity') }}</th>
								<th>{{ t('healthtracker', 'Group') }}</th>
								<th>{{ t('healthtracker', 'Report') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(row, i) in sortedResults" :key="row.reportId + '-' + i">
								<td>{{ formatDate(row.testDate) }}</td>
								<td>{{ row.testName }}</td>
								<td class="num" :style="{ color: severityColor(row.severity) }">
									{{ row.value }}<span v-if="row.unit" class="results__unit">{{ row.unit }}</span>
								</td>
								<td>{{ row.refRange || '—' }}</td>
								<td><SeverityBadge :severity="row.severity" /></td>
								<td>{{ row.testGroup || '—' }}</td>
								<td>{{ reportLabel(row) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>
			<NcEmptyContent
				v-else
				:name="t('healthtracker', 'No results yet')"
				:description="t('healthtracker', 'Import lab reports to see individual test results here.')">
				<template #icon>
					<TableArrowRight :size="40" />
				</template>
			</NcEmptyContent>
		</section>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import TableArrowRight from 'vue-material-design-icons/TableArrowRight.vue'
import api from '../api.js'
import SeverityBadge from '../components/SeverityBadge.vue'
import SkeletonList from '../components/SkeletonList.vue'
import { severityMeta } from '../store.js'

export default {
	name: 'Results',
	components: { NcEmptyContent, NcSelect, SeverityBadge, SkeletonList, TableArrowRight },

	data() {
		return {
			testNames: [],
			testName: null,
			from: '',
			to: '',
			results: [],
			loading: false,
		}
	},

	computed: {
		sortedResults() {
			return [...this.results].sort((a, b) => {
				const da = (a.testDate || '') + ''
				const db = (b.testDate || '') + ''
				return db.localeCompare(da)
			})
		},
	},

	watch: {
		testName() { this.loadResults() },
		from() { this.loadResults() },
		to() { this.loadResults() },
	},

	async mounted() {
		try {
			this.testNames = await api.getTestNames()
		} catch (e) {
			console.error('HealthTracker: could not load test names', e)
		}
		await this.loadResults()
	},

	methods: {
		t,

		async loadResults() {
			this.loading = true
			try {
				const params = {}
				if (this.testName) params.testName = this.testName
				if (this.from) params.from = this.from
				if (this.to) params.to = this.to
				this.results = await api.listResults(params)
			} catch (e) {
				console.error('HealthTracker: could not load results', e)
				this.results = []
			} finally {
				this.loading = false
			}
		},

		severityColor(severity) {
			return severityMeta(severity).text
		},

		reportLabel(row) {
			const parts = []
			if (row.reportDate) parts.push(this.formatDate(row.reportDate))
			if (row.labName) parts.push(row.labName)
			return parts.length ? parts.join(' · ') : (row.reportId ? `#${row.reportId}` : '—')
		},

		formatDate(d) {
			if (!d) return '—'
			return String(d).slice(0, 10)
		},
	},
}
</script>

<style scoped lang="scss">
.results {
	&__controls {
		display: flex;
		gap: 16px;
		align-items: flex-end;
		flex-wrap: wrap;
	}

	&__select {
		min-width: 220px;
	}

	&__date {
		display: flex;
		flex-direction: column;
		gap: 4px;
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);

		input {
			padding: 6px 8px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-small, 4px);
			background: var(--color-main-background);
			color: var(--color-main-text);
		}
	}

	&__unit {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
		margin-inline-start: 4px;
	}
}
</style>

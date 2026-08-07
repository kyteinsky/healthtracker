<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page page--narrow page--roomy">
		<header class="page__header page__header--center">
			<h2 class="page__title">
				{{ t('healthtracker', 'Trends') }}
			</h2>
		</header>

		<section class="trends__controls">
			<NcSelect
				v-model="testName"
				:options="testNames"
				:placeholder="t('healthtracker', 'Select a test to chart')"
				:clearable="true"
				:searchable="true"
				class="trends__select" />
		</section>

		<section v-if="!testName" class="trends__empty">
			<NcEmptyContent
				:name="t('healthtracker', 'No test selected')"
				:description="t('healthtracker', 'Pick a test above to see how your values change over time.')">
				<template #icon>
					<ChartLine :size="40" />
				</template>
			</NcEmptyContent>
		</section>

		<template v-else>
			<section v-if="loading" class="trends__loading">
				<NcLoadingIcon :size="28" />
			</section>
			<template v-else-if="series.length">
				<section class="trends__summary">
					<div class="trends__summary-item">
						<span class="trends__summary-label">{{ t('healthtracker', 'Latest value') }}</span>
						<span class="trends__summary-value" :style="{ color: severityColor(latest.severity) }">
							{{ latest.value }}<span v-if="latest.unit" class="trends__unit">{{ latest.unit }}</span>
						</span>
					</div>
					<div class="trends__summary-item">
						<span class="trends__summary-label">{{ t('healthtracker', 'Reference range') }}</span>
						<span class="trends__summary-value">{{ refRangeLabel }}</span>
					</div>
					<div class="trends__summary-item">
						<span class="trends__summary-label">{{ t('healthtracker', 'Results') }}</span>
						<span class="trends__summary-value">{{ series.length }}</span>
					</div>
				</section>

				<section class="trends__chart">
					<TrendChart :title="testName" :data="series" />
				</section>

				<section class="trends__points">
					<div class="table-wrap">
						<table class="tbl">
							<thead>
								<tr>
									<th>{{ t('healthtracker', 'Date') }}</th>
									<th>{{ t('healthtracker', 'Value') }}</th>
									<th>{{ t('healthtracker', 'Unit') }}</th>
									<th>{{ t('healthtracker', 'Ref range') }}</th>
									<th>{{ t('healthtracker', 'Severity') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(p, i) in chronologicalSeries" :key="i">
									<td>{{ formatDate(p.date) }}</td>
									<td class="num">{{ p.value }}</td>
									<td>{{ p.unit || '—' }}</td>
									<td>{{ formatRef(p) }}</td>
									<td><SeverityBadge :severity="p.severity" /></td>
								</tr>
							</tbody>
						</table>
					</div>
				</section>
			</template>
			<section v-else class="trends__empty">
				<NcEmptyContent
					:name="t('healthtracker', 'No data for this test')"
					:description="t('healthtracker', 'Once results for this test are imported, the trend will appear here.')">
					<template #icon>
						<ChartLine :size="40" />
					</template>
				</NcEmptyContent>
			</section>
		</template>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import api from '../api.js'
import SeverityBadge from '../components/SeverityBadge.vue'
import TrendChart from '../components/TrendChart.vue'
import { severityMeta } from '../store.js'

export default {
	name: 'Trends',
	components: { NcEmptyContent, NcLoadingIcon, NcSelect, SeverityBadge, TrendChart, ChartLine },

	data() {
		return {
			testNames: [],
			testName: null,
			series: [],
			loading: false,
		}
	},

	computed: {
		/** API returns oldest-first; show latest as the last element. */
		latest() {
			return this.series[this.series.length - 1] || {}
		},

		chronologicalSeries() {
			// Newest first for the table.
			return [...this.series].reverse()
		},

		refRangeLabel() {
			const p = this.series.find((d) => d.refLow !== null && d.refHigh !== null)
			if (!p) return '—'
			return `${p.refLow}–${p.refHigh}${p.unit ? ' ' + p.unit : ''}`
		},
	},

	watch: {
		testName() { this.loadTrend() },
	},

	async mounted() {
		try {
			this.testNames = await api.getTestNames()
		} catch (e) {
			console.error('HealthTracker: could not load test names', e)
		}
	},

	methods: {
		t,

		async loadTrend() {
			if (!this.testName) {
				this.series = []
				return
			}
			this.loading = true
			try {
				this.series = await api.getTrend(this.testName)
			} catch (e) {
				console.error('HealthTracker: could not load trend', e)
				this.series = []
			} finally {
				this.loading = false
			}
		},

		severityColor(severity) {
			return severityMeta(severity).text
		},

		formatDate(d) {
			if (!d) return '—'
			return String(d).slice(0, 10)
		},

		formatRef(p) {
			if (p.refLow !== null && p.refHigh !== null && p.refLow !== undefined && p.refHigh !== undefined) {
				return `${p.refLow}–${p.refHigh}`
			}
			return p.refRange || '—'
		},
	},
}
</script>

<style scoped lang="scss">
.trends {
	&__controls {
		display: flex;
		gap: 16px;
		align-items: flex-end;
		flex-wrap: wrap;
	}

	&__select {
		min-width: 260px;
	}

	&__summary {
		display: flex;
		gap: 24px;
		flex-wrap: wrap;
		padding: 12px 14px;
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-background-dark);
	}

	&__summary-item {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__summary-label {
		font-size: 0.75rem;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		color: var(--color-text-maxcontrast);
	}

	&__summary-value {
		font-size: 1.1rem;
		font-weight: 600;
		font-variant-numeric: tabular-nums;
	}

	&__unit {
		color: var(--color-text-maxcontrast);
		font-size: 0.8em;
		font-weight: 400;
		margin-inline-start: 4px;
	}

	&__loading {
		display: flex;
		justify-content: center;
		padding: 24px;
	}
}
</style>

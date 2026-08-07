<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Theme-aware SVG line chart for a single test's trend over time. No external
  - dependency. Plots valueNumeric by date, draws the reference range band
  - (refLow..refHigh) as a faint horizontal band when both bounds are present,
  - and colours each point by its severity tier.
-->
<template>
	<figure class="trend">
		<figcaption v-if="title" class="trend__title">
			{{ title }}
		</figcaption>
		<svg
			:viewBox="`0 0 ${width} ${height}`"
			class="trend__svg"
			role="img"
			:aria-label="title"
			preserveAspectRatio="none">
			<line
				v-for="(g, i) in gridlines"
				:key="'g' + i"
				class="trend__grid"
				:x1="padX"
				:x2="width - padX"
				:y1="g"
				:y2="g" />
			<!-- Reference range band (refLow..refHigh), faint horizontal rectangle -->
			<rect
				v-if="hasRefBand"
				class="trend__refband"
				:x="padX"
				:y="yFor(refHigh)"
				:width="width - padX * 2"
				:height="Math.max(0, yFor(refLow) - yFor(refHigh))" />
			<!-- Reference range bound lines + labels -->
			<template v-if="hasRefBand">
				<line
					class="trend__refline"
					:x1="padX"
					:x2="width - padX"
					:y1="yFor(refHigh)"
					:y2="yFor(refHigh)" />
				<line
					class="trend__refline"
					:x1="padX"
					:x2="width - padX"
					:y1="yFor(refLow)"
					:y2="yFor(refLow)" />
			</template>
			<path class="trend__area" :d="areaPath" />
			<path ref="line" class="trend__stroke" :d="linePath" />
			<g v-for="(p, i) in points" :key="'p' + i">
				<circle
					class="trend__dot"
					:cx="p.x"
					:cy="p.y"
					r="3.5"
					:style="{ fill: p.tint, stroke: p.tint }" />
				<text
					v-if="showLabel(i)"
					class="trend__xlabel"
					:x="p.x"
					:y="height - 4"
					text-anchor="middle">{{ p.label }}</text>
			</g>
		</svg>
		<div v-if="hasRefBand" class="trend__legend">
			<span class="trend__legend-item">
				<span class="trend__legend-swatch trend__legend-swatch--band" />
				{{ refLow }}–{{ refHigh }} {{ unitLabel }}
			</span>
		</div>
	</figure>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { severityMeta } from '../store.js'

const PROCESSING_STATUSES = [] // reserved for future use

export default {
	name: 'TrendChart',
	props: {
		title: { type: String, default: '' },
		// [{ date, value, valueNumeric, unit, refLow, refHigh, severity, reportId }]
		data: { type: Array, required: true },
	},

	data() {
		return { width: 680, height: 240, padX: 40, padTop: 16, padBottom: 28 }
	},

	computed: {
		unitLabel() {
			return this.data.find((d) => d.unit)?.unit || ''
		},

		/** First point carrying a numeric refLow/refHigh anchors the band. */
		refLow() {
			const p = this.data.find((d) => d.refLow !== null && d.refLow !== undefined)
			return p ? Number(p.refLow) : null
		},

		refHigh() {
			const p = this.data.find((d) => d.refHigh !== null && d.refHigh !== undefined)
			return p ? Number(p.refHigh) : null
		},

		hasRefBand() {
			return this.refLow !== null && this.refHigh !== null && this.refLow <= this.refHigh
		},

		/** Y range includes every plotted value and the reference bounds. */
		yMin() {
			const vals = this.numericValues
			const lo = this.hasRefBand ? this.refLow : null
			const candidates = vals.concat(lo !== null ? [lo] : [])
			return candidates.length ? Math.min(...candidates) : 0
		},

		yMax() {
			const vals = this.numericValues
			const hi = this.hasRefBand ? this.refHigh : null
			const candidates = vals.concat(hi !== null ? [hi] : [])
			return candidates.length ? Math.max(...candidates) : 1
		},

		ySpan() {
			let span = this.yMax - this.yMin
			if (span === 0) {
				span = Math.max(1, Math.abs(this.yMax))
			}
			// Pad so points/band don't kiss the edges.
			return span * 1.12
		},

		yBase() {
			// Shift the visible baseline below the minimum by the padding.
			return this.yMin - (this.ySpan - (this.yMax - this.yMin)) / 2
		},

		numericValues() {
			return this.data
				.map((d) => Number(d.valueNumeric))
				.filter((v) => !Number.isNaN(v))
		},

		points() {
			const n = this.data.length
			const usableW = this.width - this.padX * 2
			const usableH = this.height - this.padTop - this.padBottom
			return this.data.map((d, i) => {
				const v = Number(d.valueNumeric)
				const meta = severityMeta(d.severity)
				return {
					x: this.padX + (n <= 1 ? usableW / 2 : (usableW * i) / (n - 1)),
					y: this.padTop + usableH * (1 - (v - this.yBase) / this.ySpan),
					label: this.formatDate(d.date),
					value: v,
					tint: meta.tint,
					text: meta.text,
				}
			})
		},

		linePath() {
			return this.points
				.filter((p) => !Number.isNaN(p.value))
				.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)} ${p.y.toFixed(1)}`)
				.join(' ')
		},

		areaPath() {
			const pts = this.points.filter((p) => !Number.isNaN(p.value))
			if (!pts.length) {
				return ''
			}
			const base = this.height - this.padBottom
			const first = pts[0]
			const last = pts[pts.length - 1]
			return `M${first.x} ${base} ` + pts.map((p) => `L${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ') + ` L${last.x} ${base} Z`
		},

		gridlines() {
			const usableH = this.height - this.padTop - this.padBottom
			return [0, 0.5, 1].map((f) => this.padTop + usableH * f)
		},
	},

	methods: {
		t,
		/** Map a value to its vertical pixel position within the chart area. */
		yFor(value) {
			const usableH = this.height - this.padTop - this.padBottom
			return this.padTop + usableH * (1 - (value - this.yBase) / this.ySpan)
		},

		showLabel(i) {
			// Avoid crowding: with many points, label every other one.
			const step = this.data.length > 8 ? 2 : 1
			return i % step === 0
		},

		formatDate(d) {
			if (!d) return ''
			// The API sends ISO dates (YYYY-MM-DD); trim the time part for the axis.
			return String(d).slice(5, 10)
		},
	},
}
</script>

<style scoped lang="scss">
.trend {
	margin: 0;

	&__title {
		font-weight: 600;
		margin-bottom: 8px;
	}

	&__svg {
		width: 100%;
		height: auto;
	}

	&__grid {
		stroke: var(--color-border);
		stroke-width: 1;
	}

	&__refband {
		fill: color-mix(in srgb, var(--color-success) 12%, transparent);
	}

	&__refline {
		stroke: color-mix(in srgb, var(--color-success) 45%, transparent);
		stroke-width: 1;
		stroke-dasharray: 4 3;
	}

	&__area {
		fill: color-mix(in srgb, var(--color-primary-element) 16%, transparent);
	}

	&__stroke {
		fill: none;
		stroke: var(--color-primary-element);
		stroke-width: 2.5;
		stroke-linejoin: round;
		stroke-linecap: round;
	}

	&__dot {
		stroke-width: 2;
	}

	&__xlabel {
		font-size: 10px;
		fill: var(--color-text-maxcontrast);
	}

	&__legend {
		display: flex;
		gap: 16px;
		flex-wrap: wrap;
		margin-top: 8px;
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
	}

	&__legend-item {
		display: inline-flex;
		align-items: center;
		gap: 6px;
	}

	&__legend-swatch {
		width: 14px;
		height: 10px;
		border-radius: 3px;

		&--band {
			background: color-mix(in srgb, var(--color-success) 24%, transparent);
			border: 1px dashed color-mix(in srgb, var(--color-success) 45%, transparent);
		}
	}
}
</style>

<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span class="severity-badge" :style="{ '--badge-text': meta.text, '--badge-tint': meta.tint }">
		{{ meta.label }}
	</span>
</template>

<script>
import { severityMeta } from '../store.js'

export default {
	name: 'SeverityBadge',
	props: {
		severity: { type: String, required: true },
	},

	computed: {
		meta() {
			return severityMeta(this.severity)
		},
	},
}
</script>

<style scoped lang="scss">
.severity-badge {
	display: inline-flex;
	align-items: center;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 0.8rem;
	font-weight: 600;
	// Contrast-optimised text on a solid tinted background (not transparent), so
	// readability doesn't depend on whatever surface sits behind the pill.
	color: var(--badge-text);
	background: color-mix(in srgb, var(--badge-tint) 18%, var(--color-main-background));
	border: 1px solid color-mix(in srgb, var(--badge-tint) 35%, transparent);
	white-space: nowrap;
	text-transform: capitalize;
}
</style>

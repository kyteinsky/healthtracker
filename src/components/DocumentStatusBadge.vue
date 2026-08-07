<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span class="status-badge" :style="{ '--badge-text': meta.text, '--badge-tint': meta.tint }">
		<span class="status-badge__dot" aria-hidden="true" />
		{{ meta.label }}
	</span>
</template>

<script>
import { t } from '@nextcloud/l10n'

const MAP = {
	PENDING: { label: t('healthtracker', 'Pending'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)' },
	OCR_RUNNING: { label: t('healthtracker', 'OCR running'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)' },
	OCR_DONE: { label: t('healthtracker', 'OCR done'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)' },
	EXTRACTION_RUNNING: { label: t('healthtracker', 'Extracting'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)' },
	OCR_FAILED: { label: t('healthtracker', 'OCR failed'), text: 'var(--color-error-text)', tint: 'var(--color-error)' },
	FAILED: { label: t('healthtracker', 'Failed'), text: 'var(--color-error-text)', tint: 'var(--color-error)' },
	DONE: { label: t('healthtracker', 'Done'), text: 'var(--color-success-text)', tint: 'var(--color-success)' },
}

export default {
	name: 'DocumentStatusBadge',
	props: {
		status: { type: String, required: true },
	},

	computed: {
		meta() {
			return MAP[this.status] || { label: this.status, text: 'var(--color-main-text)', tint: 'var(--color-text-maxcontrast)' }
		},
	},
}
</script>

<style scoped lang="scss">
.status-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 0.8rem;
	font-weight: 600;
	// Contrast-optimised text on a solid tinted background (not transparent), so
	// readability doesn't depend on whatever surface sits behind the badge.
	color: var(--badge-text);
	background: color-mix(in srgb, var(--badge-tint) 18%, var(--color-main-background));
	border: 1px solid color-mix(in srgb, var(--badge-tint) 35%, transparent);
	white-space: nowrap;

	&__dot {
		width: 8px;
		height: 8px;
		border-radius: 50%;
		background: var(--badge-tint);
		flex: 0 0 auto;
	}
}
</style>

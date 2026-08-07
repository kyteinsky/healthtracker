import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lightweight reactive store shared across views (no Vuex/Pinia dependency).
 */
import { reactive } from 'vue'
import api from './api.js'

export const store = reactive({
	uid: null,
	providers: { ocr: false, llm: false },
	documents: [],
	reports: [],
	loading: false,
	selectedReportId: null,

	// ---- actions ----
	async loadSession() {
		try {
			const session = await api.getSession()
			this.uid = session.uid
			this.providers = session.providers || { ocr: false, llm: false }
		} catch (e) {
			console.error('HealthTracker: failed to load session', e)
		}
	},

	async refreshDocuments() {
		this.loading = true
		try {
			this.documents = await api.listDocuments()
		} catch (e) {
			showError(t('healthtracker', 'Could not load documents'))
		} finally {
			this.loading = false
		}
	},

	async refreshReports() {
		this.loading = true
		try {
			this.reports = await api.listReports()
		} catch (e) {
			showError(t('healthtracker', 'Could not load reports'))
		} finally {
			this.loading = false
		}
	},

	selectReport(id) {
		this.selectedReportId = id
	},
})

/**
 * Visual metadata for a test-result severity tier (spec §data model).
 * `text` uses Nextcloud's contrast-optimised *-text variables so labels stay
 * readable; `tint` is the base semantic colour used for the badge background.
 *
 * @param severity
 */
export function severityMeta(severity) {
	switch (severity) {
		case 'normal':
			return { label: t('healthtracker', 'Normal'), text: 'var(--color-success-text)', tint: 'var(--color-success)' }
		case 'low':
			return { label: t('healthtracker', 'Low'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)' }
		case 'high':
			return { label: t('healthtracker', 'High'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)' }
		case 'mild':
			return { label: t('healthtracker', 'Mild'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)' }
		case 'cautious':
			return { label: t('healthtracker', 'Cautious'), text: 'var(--color-error-text)', tint: 'var(--color-error)' }
		case 'severe':
			return { label: t('healthtracker', 'Severe'), text: 'var(--color-error-text)', tint: 'var(--color-error)' }
		case 'abnormal':
			return { label: t('healthtracker', 'Abnormal'), text: 'var(--color-error-text)', tint: 'var(--color-error)' }
		case 'unknown':
			return { label: t('healthtracker', 'Unknown'), text: 'var(--color-text-maxcontrast)', tint: 'var(--color-text-maxcontrast)' }
		default:
			return { label: severity, text: 'var(--color-main-text)', tint: 'var(--color-text-maxcontrast)' }
	}
}

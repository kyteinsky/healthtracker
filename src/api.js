/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/healthtracker' + path)

export default {
	// Session & provider availability
	getSession: () => axios.get(url('/api/session')).then((r) => r.data),

	// Documents (input files)
	listDocuments: (params) => axios.get(url('/api/documents'), { params }).then((r) => r.data),
	createDocuments: (fileIds) => axios.post(url('/api/documents'), { fileIds }).then((r) => r.data),
	getDocument: (id) => axios.get(url(`/api/documents/${id}`)).then((r) => r.data),
	deleteDocument: (id) => axios.delete(url(`/api/documents/${id}`)).then((r) => r.data),
	processDocument: (id) => axios.post(url(`/api/documents/${id}/process`)).then((r) => r.data),
	getOcrText: (id) => axios.get(url(`/api/documents/${id}/ocr`)).then((r) => r.data),

	// Reports (parsed lab reports)
	listReports: (params) => axios.get(url('/api/reports'), { params }).then((r) => r.data),
	getReport: (id) => axios.get(url(`/api/reports/${id}`)).then((r) => r.data),
	updateReport: (id, data) => axios.put(url(`/api/reports/${id}`), data).then((r) => r.data),
	deleteReport: (id) => axios.delete(url(`/api/reports/${id}`)).then((r) => r.data),
	getReportTests: (id) => axios.get(url(`/api/reports/${id}/tests`)).then((r) => r.data),

	// Results (flat chronological test list)
	listResults: (params) => axios.get(url('/api/results'), { params }).then((r) => r.data),

	// Trends
	getTrend: (testName) => axios.get(url(`/api/trends/${encodeURIComponent(testName)}`)).then((r) => r.data),
	getTestNames: () => axios.get(url('/api/test-names')).then((r) => r.data),
}

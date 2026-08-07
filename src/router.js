/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
	{ path: '/', redirect: '/documents' },
	{
		path: '/documents',
		name: 'documents',
		component: () => import('./views/Documents.vue'),
	},
	{
		path: '/results',
		name: 'results',
		component: () => import('./views/Results.vue'),
	},
	{
		path: '/trends',
		name: 'trends',
		component: () => import('./views/Trends.vue'),
	},
]

export default createRouter({
	history: createWebHashHistory(),
	routes,
})

<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		// SPA entry (hash-based routing on the client, so a single entry suffices)
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Bootstrap: session + provider availability for the SPA
		['name' => 'config#session', 'url' => '/api/session', 'verb' => 'GET'],

		// Documents (input files being processed)
		['name' => 'document#index', 'url' => '/api/documents', 'verb' => 'GET'],
		['name' => 'document#create', 'url' => '/api/documents', 'verb' => 'POST'],
		['name' => 'document#show', 'url' => '/api/documents/{id}', 'verb' => 'GET'],
		['name' => 'document#destroy', 'url' => '/api/documents/{id}', 'verb' => 'DELETE'],
		['name' => 'document#process', 'url' => '/api/documents/{id}/process', 'verb' => 'POST'],
		['name' => 'document#ocrText', 'url' => '/api/documents/{id}/ocr', 'verb' => 'GET'],

		// Reports (parsed lab reports) + their test results
		['name' => 'report#index', 'url' => '/api/reports', 'verb' => 'GET'],
		['name' => 'report#show', 'url' => '/api/reports/{id}', 'verb' => 'GET'],
		['name' => 'report#update', 'url' => '/api/reports/{id}', 'verb' => 'PUT'],
		['name' => 'report#destroy', 'url' => '/api/reports/{id}', 'verb' => 'DELETE'],
		['name' => 'report#tests', 'url' => '/api/reports/{id}/tests', 'verb' => 'GET'],

		// Test results: chronological table + per-test trend series
		['name' => 'report#results', 'url' => '/api/results', 'verb' => 'GET'],
		['name' => 'report#trend', 'url' => '/api/trends/{testName}', 'verb' => 'GET'],
		['name' => 'report#testNames', 'url' => '/api/test-names', 'verb' => 'GET'],
	],
];

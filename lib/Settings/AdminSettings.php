<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Settings;

use OCA\HealthTracker\AppInfo\Application;
use OCA\HealthTracker\Service\ProviderService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

/**
 * Admin settings panel. Health Tracker has no app-specific toggles — providers
 * (OCR + LLM) are enabled in Nextcloud's central AI admin settings. This panel
 * surfaces current provider availability so the admin can confirm everything is
 * wired up before users start uploading lab reports.
 */
class AdminSettings implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private ProviderService $providerService,
	) {
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getPriority(): int {
		return 80;
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('admin-providers', [
			'ocr' => $this->providerService->hasOcr(),
			'llm' => $this->providerService->hasLlm(),
		]);

		return new TemplateResponse(Application::APP_ID, 'settings/admin');
	}
}

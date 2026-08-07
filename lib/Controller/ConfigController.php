<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Controller;

use OCA\HealthTracker\Service\ProviderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ConfigController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ProviderService $providerService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Bootstrap endpoint: session + provider availability for the SPA.
	 */
	#[NoAdminRequired]
	public function session(): DataResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';

		return new DataResponse([
			'uid' => $uid,
			'providers' => [
				'ocr' => $this->providerService->hasOcr(),
				'llm' => $this->providerService->hasLlm(),
			],
		]);
	}
}

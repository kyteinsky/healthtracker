<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Service;

use OCP\TaskProcessing\IManager;

/**
 * Thin wrapper around {@see IManager} that answers the two availability
 * questions Health Tracker cares about: is an OCR provider enabled, and is an
 * LLM (text2text) provider enabled.
 *
 * The actual provider configuration happens in Nextcloud's AI admin settings;
 * this service only reads availability so the UI can warn the user and the
 * orchestrator can decide whether to schedule tasks.
 */
class ProviderService {
	public const OCR_TASK_TYPE_ID = 'core:image2text:ocr';
	public const LLM_TASK_TYPE_ID = 'core:text2text';

	public function __construct(
		private IManager $manager,
	) {
	}

	/**
	 * Whether an OCR provider (core:image2text:ocr) is available.
	 *
	 * Uses {@see IManager::getAvailableTaskTypeIds()} which is cheaper than
	 * getAvailableTaskTypes() since it skips metadata computation.
	 */
	public function hasOcr(): bool {
		return in_array(self::OCR_TASK_TYPE_ID, $this->manager->getAvailableTaskTypeIds(), true);
	}

	/** Whether an LLM provider (core:text2text) is available. */
	public function hasLlm(): bool {
		return in_array(self::LLM_TASK_TYPE_ID, $this->manager->getAvailableTaskTypeIds(), true);
	}

	/**
	 * Availability summary for the SPA session endpoint.
	 *
	 * @return array{ocr: bool, llm: bool}
	 */
	public function getAvailability(): array {
		return [
			'ocr' => $this->hasOcr(),
			'llm' => $this->hasLlm(),
		];
	}
}

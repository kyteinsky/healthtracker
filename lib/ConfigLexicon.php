<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker;

use OCA\HealthTracker\Service\ConfigService;
use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;

/**
 * Config lexicon for the healthtracker app.
 *
 * Provider configuration (OCR + LLM) is performed in Nextcloud's own AI admin
 * settings, not here, so this app keeps a minimal admin surface. The single
 * optional key lets an admin override the LLM extraction prompt used by
 * {@see ConfigService::getExtractionPrompt()}.
 *
 * {@see ILexicon}
 */
class ConfigLexicon implements ILexicon {
	/** Admin key: optional override for the LLM extraction prompt. */
	public const KEY_EXTRACTION_PROMPT = 'extraction_prompt';

	#[\Override]
	public function getStrictness(): Strictness {
		return Strictness::NOTICE;
	}

	#[\Override]
	public function getAppConfigs(): array {
		return [
			new Entry(
				self::KEY_EXTRACTION_PROMPT,
				ValueType::STRING,
				'',
				'Optional override for the LLM prompt that extracts structured data from OCR\'d lab reports. Leave empty to use the built-in default.',
				lazy: true,
			),
		];
	}

	#[\Override]
	public function getUserConfigs(): array {
		// Health Tracker has no per-user personal config keys.
		return [];
	}
}

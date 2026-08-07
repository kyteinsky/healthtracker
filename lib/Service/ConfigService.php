<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Service;

use OCA\HealthTracker\ConfigLexicon;
use OCP\IAppConfig;

/**
 * Typed access to the healthtracker app's admin settings.
 *
 * The only admin knob is the optional extraction-prompt override
 * ({@see ConfigLexicon::KEY_EXTRACTION_PROMPT}); everything else is declared in
 * the config lexicon and surfaced via {@see self::getExtractionPrompt()}.
 */
class ConfigService {
	public const APP_ID = 'healthtracker';

	/**
	 * Built-in default prompt for the LLM extraction task. Used when no admin
	 * override is set. Kept in sync with
	 * {@see \OCA\HealthTracker\Service\ExtractionService::buildPrompt()} which
	 * embeds this prefix before the OCR text.
	 */
	public const DEFAULT_PROMPT = <<<'PROMPT'
You are a medical lab-report parser. Read the lab report text below and return STRICT JSON only — no prose, no markdown fences.

Detect the language used in the text and answer in that same language without mentioning the language explicitly.

Return exactly this JSON shape (omit nothing; use null where unknown):
{
  "patient_name": string|null,
  "test_date": string|null (YYYY-MM-DD),
  "report_date": string|null (YYYY-MM-DD),
  "doctor_name": string|null,
  "lab_name": string|null,
  "report_type": string|null,
  "notes": string|null,
  "tests": [
    {
      "test_name": string,
      "value": string,
      "unit": string|null,
      "ref_range": string|null,
      "severity": "normal|low|high|mild|cautious|severe|abnormal|unknown",
      "severity_note": string|null,
      "test_group": string|null
    }
  ]
}

Rules:
- "value" must be the textual value as printed, e.g. "245", ">120", "<0.5", "Negative".
- "severity" is inferred from the sheet's own flags/footnotes (H/L/Critical/mild/etc). Use "unknown" when the sheet gives no clue.
- "severity_note" is a short one-line explanation for non-normal results, in the report's language.
- "test_group" groups related tests (e.g. "Hematology", "Lipids"); null if no grouping is apparent.
- Output ONLY the JSON object.

Lab report text:
PROMPT;

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * The configured extraction prompt, falling back to the built-in default
	 * when the admin override is empty.
	 */
	public function getExtractionPrompt(): string {
		$override = $this->appConfig->getValueString(self::APP_ID, ConfigLexicon::KEY_EXTRACTION_PROMPT);
		return $override !== '' ? $override : self::DEFAULT_PROMPT;
	}

	/**
	 * The whole admin config as a plain array for the settings UI / SPA bootstrap.
	 *
	 * @return array<string,mixed>
	 */
	public function getAdminConfig(): array {
		return [
			ConfigLexicon::KEY_EXTRACTION_PROMPT => $this->appConfig->getValueString(self::APP_ID, ConfigLexicon::KEY_EXTRACTION_PROMPT),
		];
	}
}

<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Service;

use OCA\HealthTracker\Exception\ValidationException;

/**
 * Builds the LLM extraction prompt from OCR text and parses the model's JSON
 * output back into a normalized array.
 *
 * The prompt instructs the model to emit STRICT JSON matching the
 * {@see self::EXPECTED_SHAPE}; {@see self::parseExtraction()} is lenient about
 * extra fields but requires a `tests` array. Numeric helpers
 * ({@see self::normalizeValue()} / {@see self::parseRange()}) are reused by
 * {@see \OCA\HealthTracker\Service\DocumentService} when persisting test
 * results.
 */
class ExtractionService {
	/**
	 * Allowed severity tiers (must match {@see \OCA\HealthTracker\Db\TestResult::SEVERITIES}).
	 */
	public const SEVERITIES = [
		'normal', 'low', 'high', 'mild', 'cautious', 'severe', 'abnormal', 'unknown',
	];

	/** @var list<string> top-level scalar fields the model may return. */
	private const SCALAR_FIELDS = [
		'patient_name', 'test_date', 'report_date', 'doctor_name',
		'lab_name', 'report_type', 'notes',
	];

	/** @var list<string> fields each test entry may carry. */
	private const TEST_FIELDS = [
		'test_name', 'value', 'unit', 'ref_range', 'severity',
		'severity_note', 'test_group',
	];

	public function __construct(
		private ConfigService $configService,
	) {
	}

	/**
	 * Build the text2text prompt for the given OCR text.
	 *
	 * The configured (or default) prompt is emitted first, followed by the
	 * raw OCR text so the model can parse it.
	 */
	public function buildPrompt(string $ocrText): string {
		return $this->configService->getExtractionPrompt() . "\n" . $ocrText;
	}

	/**
	 * Parse the LLM output into a normalized array.
	 *
	 * Strips markdown code fences, json_decodes, validates the shape (a `tests`
	 * array is required) and returns a normalized array with only known keys.
	 * Extra fields are tolerated and dropped.
	 *
	 * @return array{patient_name: ?string, test_date: ?string, report_date: ?string, doctor_name: ?string, lab_name: ?string, report_type: ?string, notes: ?string, tests: list<array<string,mixed>>}
	 *
	 * @throws ValidationException on invalid JSON or missing/invalid `tests`.
	 */
	public function parseExtraction(string $llmOutput): array {
		$clean = $this->stripFences($llmOutput);
		$data = json_decode($clean, true);

		if (!is_array($data)) {
			throw new ValidationException('LLM output is not valid JSON.');
		}

		if (!array_key_exists('tests', $data) || !is_array($data['tests'])) {
			throw new ValidationException('LLM output is missing the required "tests" array.');
		}

		$normalized = [
			'patient_name' => $this->nullableString($data['patient_name'] ?? null),
			'test_date' => $this->nullableString($data['test_date'] ?? null),
			'report_date' => $this->nullableString($data['report_date'] ?? null),
			'doctor_name' => $this->nullableString($data['doctor_name'] ?? null),
			'lab_name' => $this->nullableString($data['lab_name'] ?? null),
			'report_type' => $this->nullableString($data['report_type'] ?? null),
			'notes' => $this->nullableString($data['notes'] ?? null),
			'tests' => [],
		];

		foreach ($data['tests'] as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$normalized['tests'][] = $this->normalizeTestEntry($entry);
		}

		return $normalized;
	}

	/**
	 * Extract the first numeric value from a result string.
	 *
	 * Handles plain numbers ("245"), comparisons (">120", "<0.5"), scientific
	 * notation ("1.2e3" -> 1200.0) and signed values. Returns null when no
	 * number is present (e.g. "Negative").
	 */
	public function normalizeValue(string $value): ?float {
		// Match an optional sign, digits/decimal, and an optional exponent.
		if (preg_match('/[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?/', $value, $m)) {
			$num = (float)$m[0];
			// Guard against inf/nan from pathological inputs.
			if (is_finite($num)) {
				return $num;
			}
		}
		return null;
	}

	/**
	 * Parse a reference range of the form "A-B" into [low, high] floats.
	 *
	 * Only simple "A-B" ranges are parsed; anything else (">100", "<0.5",
	 * "Negative", empty) returns [null, null]. The raw text is preserved by
	 * the caller in the `ref_range` column.
	 *
	 * @return array{0: ?float, 1: ?float}
	 */
	public function parseRange(string $range): array {
		if ($range === '') {
			return [null, null];
		}
		// "150-450" or "1.2 - 4.8"; reject ranges that start with a comparator.
		if (preg_match('/^\s*(\d+(?:\.\d*)?|\.\d+)\s*-\s*(\d+(?:\.\d*)?|\.\d+)\s*$/', $range, $m)) {
			$low = (float)$m[1];
			$high = (float)$m[2];
			if (is_finite($low) && is_finite($high)) {
				return [$low, $high];
			}
		}
		return [null, null];
	}

	/**
	 * Strip ```json … ``` / ``` … ``` markdown fences if present.
	 */
	private function stripFences(string $output): string {
		$trimmed = trim($output);
		if ($trimmed === '') {
			return $trimmed;
		}
		// Opening fence optionally labelled (json, JSON, etc).
		if (preg_match('/^```(?:json)?\s*\n?/i', $trimmed, $m, PREG_OFFSET_CAPTURE)) {
			$trimmed = substr($trimmed, $m[0][1] + strlen($m[0][0]));
		}
		// Trailing fence.
		$trimmed = preg_replace('/\n?```\s*$/', '', $trimmed) ?? $trimmed;
		return trim($trimmed);
	}

	private function nullableString(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		$s = is_string($value) ? $value : (string)$value;
		return $s !== '' ? $s : null;
	}

	/**
	 * Normalize a single test entry: keep known fields, coerce to strings,
	 * clamp severity to the allowed set (default "unknown").
	 *
	 * @param array<string,mixed> $entry
	 * @return array<string,mixed>
	 */
	private function normalizeTestEntry(array $entry): array {
		$severity = strtolower((string)($entry['severity'] ?? 'unknown'));
		if (!in_array($severity, self::SEVERITIES, true)) {
			$severity = 'unknown';
		}

		return [
			'test_name' => $this->nullableString($entry['test_name'] ?? null) ?? '',
			'value' => $this->nullableString($entry['value'] ?? null) ?? '',
			'unit' => $this->nullableString($entry['unit'] ?? null),
			'ref_range' => $this->nullableString($entry['ref_range'] ?? null),
			'severity' => $severity,
			'severity_note' => $this->nullableString($entry['severity_note'] ?? null),
			'test_group' => $this->nullableString($entry['test_group'] ?? null),
		];
	}
}

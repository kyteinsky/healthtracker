<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Tests\Unit\Service;

use OCA\HealthTracker\Db\TestResult;
use OCA\HealthTracker\Exception\ValidationException;
use OCA\HealthTracker\Service\ConfigService;
use OCA\HealthTracker\Service\ExtractionService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExtractionServiceTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private ConfigService $configService;
	private ExtractionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		// Use the built-in default prompt (no admin override).
		$this->appConfig->method('getValueString')->willReturn('');
		$this->configService = new ConfigService($this->appConfig);
		$this->service = new ExtractionService($this->configService);
	}

	public function testBuildPromptPrependsPromptToOcrText(): void {
		$prompt = $this->service->buildPrompt("LAB REPORT\nHemoglobin 14.2");
		// The default prompt is emitted first, then the OCR text on a new line.
		$this->assertStringContainsString('LAB REPORT', $prompt);
		$this->assertStringContainsString('Hemoglobin 14.2', $prompt);
		$this->assertStringEndsWith('Hemoglobin 14.2', $prompt);
	}

	public function testParseExtractionReturnsNormalizedShape(): void {
		$json = json_encode([
			'patient_name' => 'Jane Doe',
			'test_date' => '2026-01-15',
			'report_date' => '2026-01-20',
			'doctor_name' => 'Dr. Smith',
			'lab_name' => 'CityLab',
			'report_type' => 'CBC',
			'notes' => 'Routine check',
			'tests' => [
				[
					'test_name' => 'Hemoglobin',
					'value' => '14.2',
					'unit' => 'g/dL',
					'ref_range' => '12.0-16.0',
					'severity' => 'normal',
					'severity_note' => null,
					'test_group' => 'Hematology',
				],
			],
		], JSON_THROW_ON_ERROR);

		$data = $this->service->parseExtraction($json);

		$this->assertSame('Jane Doe', $data['patient_name']);
		$this->assertSame('2026-01-15', $data['test_date']);
		$this->assertSame('CityLab', $data['lab_name']);
		$this->assertCount(1, $data['tests']);
		$this->assertSame('Hemoglobin', $data['tests'][0]['test_name']);
		$this->assertSame('normal', $data['tests'][0]['severity']);
	}

	public function testParseExtractionStripsMarkdownFences(): void {
		$json = "```json\n" . json_encode([
			'patient_name' => null,
			'tests' => [['test_name' => 'Glucose', 'value' => '92', 'severity' => 'normal']],
		], JSON_THROW_ON_ERROR) . "\n```";

		$data = $this->service->parseExtraction($json);
		$this->assertCount(1, $data['tests']);
		$this->assertSame('Glucose', $data['tests'][0]['test_name']);
	}

	public function testParseExtractionClampsUnknownSeverityToUnknown(): void {
		$json = json_encode([
			'tests' => [
				['test_name' => 'X', 'value' => '1', 'severity' => 'CATASTROPHIC'],
			],
		], JSON_THROW_ON_ERROR);

		$data = $this->service->parseExtraction($json);
		$this->assertSame('unknown', $data['tests'][0]['severity']);
	}

	public function testParseExtractionLowercasesSeverity(): void {
		$json = json_encode([
			'tests' => [
				['test_name' => 'X', 'value' => '1', 'severity' => 'SEVERE'],
			],
		], JSON_THROW_ON_ERROR);

		$data = $this->service->parseExtraction($json);
		$this->assertSame('severe', $data['tests'][0]['severity']);
	}

	public function testParseExtractionThrowsOnInvalidJson(): void {
		$this->expectException(ValidationException::class);
		$this->service->parseExtraction('not json at all');
	}

	public function testParseExtractionThrowsWhenTestsMissing(): void {
		$json = json_encode(['patient_name' => 'Jane'], JSON_THROW_ON_ERROR);
		$this->expectException(ValidationException::class);
		$this->service->parseExtraction($json);
	}

	public function testParseExtractionThrowsWhenTestsNotArray(): void {
		$json = json_encode(['tests' => 'nope'], JSON_THROW_ON_ERROR);
		$this->expectException(ValidationException::class);
		$this->service->parseExtraction($json);
	}

	public function testParseExtractionDropsNonArrayTestEntries(): void {
		$json = json_encode([
			'tests' => [
				['test_name' => 'Good', 'value' => '1', 'severity' => 'normal'],
				'not-an-array',
				['test_name' => 'AlsoGood', 'value' => '2', 'severity' => 'high'],
			],
		], JSON_THROW_ON_ERROR);

		$data = $this->service->parseExtraction($json);
		$this->assertCount(2, $data['tests']);
		$this->assertSame('Good', $data['tests'][0]['test_name']);
		$this->assertSame('AlsoGood', $data['tests'][1]['test_name']);
	}

	/**
	 * @return array<string, array{0:string, 1:?float}>
	 */
	public static function valueProvider(): array {
		return [
			'plain integer' => ['245', 245.0],
			'decimal' => ['14.2', 14.2],
			'greater-than' => ['>120', 120.0],
			'less-than' => ['<0.5', 0.5],
			'scientific' => ['1.2e3', 1200.0],
			'negative' => ['-3.5', -3.5],
			'leading decimal' => ['.5', 0.5],
			'unit suffix' => ['10^3/µL', 10.0],
			'text only' => ['Negative', null],
			'empty' => ['', null],
			'greater-equal' => ['>= 100', 100.0],
		];
	}

	/**
	 * @dataProvider valueProvider
	 */
	public function testNormalizeValue(string $input, ?float $expected): void {
		$this->assertSame($expected, $this->service->normalizeValue($input));
	}

	/**
	 * @return array<string, array{0:string, 1:?float, 2:?float}>
	 */
	public static function rangeProvider(): array {
		return [
			'simple range' => ['150-450', 150.0, 450.0],
			'spaced range' => ['1.2 - 4.8', 1.2, 4.8],
			'greater-than no range' => ['>100', null, null],
			'less-than no range' => ['<0.5', null, null],
			'text no range' => ['Negative', null, null],
			'empty' => ['', null, null],
		];
	}

	/**
	 * @dataProvider rangeProvider
	 */
	public function testParseRange(string $input, ?float $expectedLow, ?float $expectedHigh): void {
		[$low, $high] = $this->service->parseRange($input);
		$this->assertSame($expectedLow, $low);
		$this->assertSame($expectedHigh, $high);
	}

	public function testSeverityConstantsAreConsistentWithEntity(): void {
		// The service's allowed list must match the entity's canonical list.
		$this->assertSame(
			array_values(TestResult::SEVERITIES),
			array_values(ExtractionService::SEVERITIES),
		);
	}
}

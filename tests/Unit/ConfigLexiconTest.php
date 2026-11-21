<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Tests\Unit\Service;

use OCA\HealthTracker\ConfigLexicon;
use OCA\HealthTracker\Service\ConfigService;
use OCP\Config\Lexicon\Preset;
use OCP\Config\ValueType;
use PHPUnit\Framework\TestCase;

class ConfigLexiconTest extends TestCase {
	private ConfigLexicon $lexicon;

	protected function setUp(): void {
		parent::setUp();
		$this->lexicon = new ConfigLexicon();
	}

	public function testDeclaresExtractionPromptKey(): void {
		$entries = $this->lexicon->getAppConfigs();
		$keys = array_map(static fn ($e) => $e->getKey(), $entries);
		$this->assertContains(ConfigLexicon::KEY_EXTRACTION_PROMPT, $keys);
	}

	public function testExtractionPromptIsStringWithEmptyDefault(): void {
		$entries = $this->lexicon->getAppConfigs();
		$entry = null;
		foreach ($entries as $e) {
			if ($e->getKey() === ConfigLexicon::KEY_EXTRACTION_PROMPT) {
				$entry = $e;
				break;
			}
		}
		$this->assertNotNull($entry);
		$this->assertSame(ValueType::STRING, $entry->getValueType());
		$this->assertSame('', $entry->getDefault(Preset::NONE));
	}

	public function testHasNoUserConfigs(): void {
		$this->assertSame([], $this->lexicon->getUserConfigs());
	}

	public function testStrictnessIsNotice(): void {
		$this->assertSame(
			\OCP\Config\Lexicon\Strictness::NOTICE,
			$this->lexicon->getStrictness(),
		);
	}
}

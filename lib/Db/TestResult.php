<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getReportId()
 * @method void setReportId(int $reportId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getTestName()
 * @method void setTestName(string $testName)
 * @method string getValue()
 * @method void setValue(string $value)
 * @method float|null getValueNumeric()
 * @method void setValueNumeric(?float $valueNumeric)
 * @method string|null getUnit()
 * @method void setUnit(?string $unit)
 * @method string|null getRefRange()
 * @method void setRefRange(?string $refRange)
 * @method float|null getRefLow()
 * @method void setRefLow(?float $refLow)
 * @method float|null getRefHigh()
 * @method void setRefHigh(?float $refHigh)
 * @method string getSeverity()
 * @method void setSeverity(string $severity)
 * @method string|null getSeverityNote()
 * @method void setSeverityNote(?string $severityNote)
 * @method string|null getFlags()
 * @method void setFlags(?string $flags)
 * @method string|null getTestGroup()
 * @method void setTestGroup(?string $testGroup)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 */
class TestResult extends Entity implements \JsonSerializable {
	// Severity enum values (LLM-extracted per document, see contract §data model).
	public const SEVERITY_NORMAL = 'normal';
	public const SEVERITY_LOW = 'low';
	public const SEVERITY_HIGH = 'high';
	public const SEVERITY_MILD = 'mild';
	public const SEVERITY_CAUTIOUS = 'cautious';
	public const SEVERITY_SEVERE = 'severe';
	public const SEVERITY_ABNORMAL = 'abnormal';
	public const SEVERITY_UNKNOWN = 'unknown';

	/** All valid severity tiers. */
	public const SEVERITIES = [
		self::SEVERITY_NORMAL,
		self::SEVERITY_LOW,
		self::SEVERITY_HIGH,
		self::SEVERITY_MILD,
		self::SEVERITY_CAUTIOUS,
		self::SEVERITY_SEVERE,
		self::SEVERITY_ABNORMAL,
		self::SEVERITY_UNKNOWN,
	];

	protected int $reportId = 0;
	protected string $userId = '';
	protected string $testName = '';
	protected string $value = '';
	protected ?float $valueNumeric = null;
	protected ?string $unit = null;
	protected ?string $refRange = null;
	protected ?float $refLow = null;
	protected ?float $refHigh = null;
	protected string $severity = self::SEVERITY_NORMAL;
	protected ?string $severityNote = null;
	protected ?string $flags = null;
	protected ?string $testGroup = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('reportId', 'integer');
		$this->addType('valueNumeric', 'float');
		$this->addType('refLow', 'float');
		$this->addType('refHigh', 'float');
		$this->addType('createdAt', 'datetime');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'reportId' => $this->reportId,
			'userId' => $this->userId,
			'testName' => $this->testName,
			'value' => $this->value,
			'valueNumeric' => $this->valueNumeric,
			'unit' => $this->unit,
			'refRange' => $this->refRange,
			'refLow' => $this->refLow,
			'refHigh' => $this->refHigh,
			'severity' => $this->severity,
			'severityNote' => $this->severityNote,
			'flags' => $this->flags,
			'testGroup' => $this->testGroup,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}

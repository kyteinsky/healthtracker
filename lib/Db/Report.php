<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int|null getDocumentId()
 * @method void setDocumentId(?int $documentId)
 * @method string|null getPatientName()
 * @method void setPatientName(?string $patientName)
 * @method string|null getTestDate()
 * @method void setTestDate(?string $testDate)
 * @method string|null getReportDate()
 * @method void setReportDate(?string $reportDate)
 * @method string|null getDoctorName()
 * @method void setDoctorName(?string $doctorName)
 * @method string|null getLabName()
 * @method void setLabName(?string $labName)
 * @method string|null getReportType()
 * @method void setReportType(?string $reportType)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string|null getRawExtraction()
 * @method void setRawExtraction(?string $rawExtraction)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 */
class Report extends Entity implements \JsonSerializable {
	protected string $userId = '';
	protected ?int $documentId = null;
	protected ?string $patientName = null;
	protected ?string $testDate = null;
	protected ?string $reportDate = null;
	protected ?string $doctorName = null;
	protected ?string $labName = null;
	protected ?string $reportType = null;
	protected ?string $notes = null;
	protected ?string $rawExtraction = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('documentId', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'documentId' => $this->documentId,
			'patientName' => $this->patientName,
			'testDate' => $this->testDate,
			'reportDate' => $this->reportDate,
			'doctorName' => $this->doctorName,
			'labName' => $this->labName,
			'reportType' => $this->reportType,
			'notes' => $this->notes,
			'rawExtraction' => $this->rawExtraction,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}

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
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFilePath()
 * @method void setFilePath(string $filePath)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int|null getOcrTaskId()
 * @method void setOcrTaskId(?int $ocrTaskId)
 * @method int|null getExtractionTaskId()
 * @method void setExtractionTaskId(?int $extractionTaskId)
 * @method string|null getOcrText()
 * @method void setOcrText(?string $ocrText)
 * @method string|null getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method \DateTime|null getProcessedAt()
 * @method void setProcessedAt(?\DateTime $processedAt)
 */
class Document extends Entity implements \JsonSerializable {
	// Status enum values (see contract §data model).
	public const STATUS_PENDING = 'PENDING';
	public const STATUS_OCR_RUNNING = 'OCR_RUNNING';
	public const STATUS_OCR_DONE = 'OCR_DONE';
	public const STATUS_OCR_FAILED = 'OCR_FAILED';
	public const STATUS_EXTRACTION_RUNNING = 'EXTRACTION_RUNNING';
	public const STATUS_DONE = 'DONE';
	public const STATUS_FAILED = 'FAILED';

	/** Statuses that still require processing (re-queue candidates). */
	public const PENDING_STATUSES = [
		self::STATUS_PENDING,
		self::STATUS_OCR_RUNNING,
		self::STATUS_OCR_DONE,
		self::STATUS_EXTRACTION_RUNNING,
		self::STATUS_OCR_FAILED,
		self::STATUS_FAILED,
	];

	protected string $userId = '';
	protected int $fileId = 0;
	protected string $filePath = '';
	protected string $fileName = '';
	protected string $mimeType = '';
	protected string $status = self::STATUS_PENDING;
	protected ?int $ocrTaskId = null;
	protected ?int $extractionTaskId = null;
	protected ?string $ocrText = null;
	protected ?string $errorMessage = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;
	protected ?\DateTime $processedAt = null;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('ocrTaskId', 'integer');
		$this->addType('extractionTaskId', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('processedAt', 'datetime');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'fileId' => $this->fileId,
			'filePath' => $this->filePath,
			'fileName' => $this->fileName,
			'mimeType' => $this->mimeType,
			'status' => $this->status,
			'ocrTaskId' => $this->ocrTaskId,
			'extractionTaskId' => $this->extractionTaskId,
			'ocrText' => $this->ocrText,
			'errorMessage' => $this->errorMessage,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
			'processedAt' => $this->processedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}

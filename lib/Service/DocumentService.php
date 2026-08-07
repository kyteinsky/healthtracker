<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Service;

use OCA\HealthTracker\Db\Document;
use OCA\HealthTracker\Db\DocumentMapper;
use OCA\HealthTracker\Db\Report;
use OCA\HealthTracker\Db\ReportMapper;
use OCA\HealthTracker\Db\TestResult;
use OCA\HealthTracker\Db\TestResultMapper;
use OCA\HealthTracker\Exception\ForbiddenException;
use OCA\HealthTracker\Exception\NotFoundException;
use OCA\HealthTracker\Exception\ValidationException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IDBConnection;
use OCP\TaskProcessing\Exception\Exception as TaskProcessingException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the document lifecycle: creating {@see Document} rows from
 * user-picked files, scheduling OCR + LLM tasks via TaskProcessing, and
 * reacting to the success/failure events to persist reports and test results.
 *
 * Provider configuration is done in Nextcloud's AI admin settings; this service
 * uses {@see ProviderService} only to decide whether to schedule a task or to
 * park the document with an actionable error.
 */
class DocumentService {
	use TTransactional;

	/** TaskProcessing task type id for OCR. */
	private const OCR_TASK_TYPE_ID = 'core:image2text:ocr';
	/** TaskProcessing task type id for LLM extraction. */
	private const LLM_TASK_TYPE_ID = 'core:text2text';

	public function __construct(
		private DocumentMapper $documentMapper,
		private ReportMapper $reportMapper,
		private TestResultMapper $testResultMapper,
		private IManager $taskManager,
		private ConfigService $configService,
		private ExtractionService $extractionService,
		private IRootFolder $rootFolder,
		private IDBConnection $db,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Create Document rows for the given file ids and kick off OCR.
	 *
	 * Folder ids are expanded to their file children. Each file is handled
	 * independently so a single failure (missing node, scheduling error) does
	 * not abort the batch: the offending row is marked FAILED with an
	 * error_message.
	 *
	 * @param string $userId owner
	 * @param list<int> $fileIds Nextcloud file ids (files or folders)
	 * @return list<array> the created Document rows, jsonSerialize()'d
	 */
	public function addFiles(string $userId, array $fileIds): array {
		$created = [];

		foreach ($fileIds as $fileId) {
			try {
				foreach ($this->resolveFileNodes($userId, (int)$fileId) as $node) {
					$created[] = $this->createAndSchedule($userId, $node)->jsonSerialize();
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Failed to add file {fileId} for {user}: {msg}', [
					'fileId' => $fileId,
					'user' => $userId,
					'msg' => $e->getMessage(),
					'exception' => $e,
				]);
				// Still surface a FAILED row so the user sees what went wrong.
				$created[] = $this->createFailedRow($userId, (int)$fileId, $e->getMessage())->jsonSerialize();
			}
		}

		return $created;
	}

	/**
	 * Re-queue processing for an existing document.
	 *
	 * Resets status to PENDING, then schedules OCR (if no ocr_text yet) or the
	 * LLM extraction (if OCR text is already present). Used by the
	 * POST /documents/{id}/process endpoint and after a provider gets enabled.
	 */
	public function processDocument(string $userId, int $documentId): void {
		$doc = $this->findOwnedDocument($userId, $documentId);

		$doc->setStatus(Document::STATUS_PENDING);
		$doc->setErrorMessage(null);
		$doc->setUpdatedAt($this->now());
		$this->documentMapper->update($doc);

		$ocrText = $doc->getOcrText();
		if ($ocrText !== null && $ocrText !== '') {
			// OCR already done previously — jump straight to extraction.
			$this->scheduleExtraction($doc, $ocrText);
		} else {
			$this->scheduleOcr($doc);
		}
	}

	/**
	 * Handle a successful OCR task: join the output texts, store ocr_text, set
	 * status OCR_DONE, then schedule the LLM extraction task.
	 *
	 * @param int $documentId the document id stored as the task customId
	 * @param array{output: list<string>} $output raw TaskProcessing output
	 */
	public function handleOcrSuccess(int $documentId, array $output): void {
		$doc = $this->documentMapper->find($documentId);
		$texts = $output['output'] ?? [];
		if (!is_array($texts)) {
			$texts = [];
		}
		$ocrText = implode("\n\n", array_map('strval', $texts));

		$doc->setOcrText($ocrText !== '' ? $ocrText : null);
		$doc->setStatus(Document::STATUS_OCR_DONE);
		$doc->setUpdatedAt($this->now());
		$this->documentMapper->update($doc);

		$this->scheduleExtraction($doc, $ocrText);
	}

	/**
	 * Handle a successful LLM extraction task: parse the JSON output, create a
	 * Report + TestResult rows in a transaction, set document DONE.
	 *
	 * @param int $documentId the document id stored as the task customId
	 * @param array{output: string} $output raw TaskProcessing output
	 */
	public function handleExtractionSuccess(int $documentId, array $output): void {
		$doc = $this->documentMapper->find($documentId);
		$raw = (string)($output['output'] ?? '');

		try {
			$data = $this->extractionService->parseExtraction($raw);
		} catch (ValidationException $e) {
			// Unparseable LLM output — record it as a failure so the document
			// is not left stuck in EXTRACTION_RUNNING.
			$this->handleFailure($documentId, 'Could not parse extraction output: ' . $e->getMessage(), 'extraction');
			return;
		}

		$this->atomic(function () use ($doc, $data, $raw): void {
			$now = $this->now();

			$report = new Report();
			$report->setUserId($doc->getUserId());
			$report->setDocumentId($doc->getId());
			$report->setPatientName($this->nullableStr($data['patient_name'] ?? null));
			$report->setTestDate($this->nullableStr($data['test_date'] ?? null));
			$report->setReportDate($this->nullableStr($data['report_date'] ?? null));
			$report->setDoctorName($this->nullableStr($data['doctor_name'] ?? null));
			$report->setLabName($this->nullableStr($data['lab_name'] ?? null));
			$report->setReportType($this->nullableStr($data['report_type'] ?? null));
			$report->setNotes($this->nullableStr($data['notes'] ?? null));
			$report->setRawExtraction($raw !== '' ? $raw : null);
			$report->setCreatedAt($now);
			$report->setUpdatedAt($now);
			$this->reportMapper->insert($report);

			foreach ($data['tests'] ?? [] as $entry) {
				$this->insertTestResult($report, $doc->getUserId(), $entry, $now);
			}

			$doc->setStatus(Document::STATUS_DONE);
			$doc->setProcessedAt($now);
			$doc->setUpdatedAt($now);
			$this->documentMapper->update($doc);
		}, $this->db);
	}

	/**
	 * Handle a task failure: mark the document OCR_FAILED (OCR stage) or FAILED
	 * (extraction/other) and store the error message.
	 *
	 * @param int $documentId the document id stored as the task customId
	 * @param string $errorMessage the TaskProcessing error message
	 * @param string $stage 'ocr' | 'extraction' | '' (other)
	 */
	public function handleFailure(int $documentId, string $errorMessage, string $stage): void {
		try {
			$doc = $this->documentMapper->find($documentId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return;
		}

		$doc->setStatus($stage === 'ocr' ? Document::STATUS_OCR_FAILED : Document::STATUS_FAILED);
		$doc->setErrorMessage($errorMessage);
		$doc->setUpdatedAt($this->now());
		$this->documentMapper->update($doc);
	}

	/**
	 * Ownership-checked accessor for the cached OCR text.
	 *
	 * @throws NotFoundException if the document does not exist
	 * @throws ForbiddenException if the document belongs to another user
	 */
	public function getOcrText(string $userId, int $documentId): ?string {
		$doc = $this->findOwnedDocument($userId, $documentId);
		return $doc->getOcrText();
	}

	/**
	 * Delete a document and cascade-delete its reports (and their test results).
	 *
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function deleteDocument(string $userId, int $documentId): void {
		$doc = $this->findOwnedDocument($userId, $documentId);

		$this->atomic(function () use ($doc): void {
			$reports = $this->reportMapper->findByDocumentId($doc->getId());
			foreach ($reports as $report) {
				foreach ($this->testResultMapper->findByReportId($report->getId()) as $result) {
					$this->testResultMapper->delete($result);
				}
				$this->reportMapper->delete($report);
			}
			$this->documentMapper->delete($doc);
		}, $this->db);
	}

	/**
	 * List the caller's documents, optionally filtered by status.
	 *
	 * @return list<array>
	 */
	public function getDocuments(string $userId, ?string $status = null, int $limit = 200, int $offset = 0): array {
		$docs = $this->documentMapper->findFiltered($userId, $status, $limit, $offset);
		return array_map(static fn (Document $d): array => $d->jsonSerialize(), $docs);
	}

	/**
	 * Fetch a single document, ownership-checked.
	 *
	 * @return array the document, jsonSerialize()'d
	 *
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function getDocument(string $userId, int $id): array {
		$doc = $this->findOwnedDocument($userId, $id);
		return $doc->jsonSerialize();
	}

	/**
	 * Throw ForbiddenException unless the document belongs to $userId.
	 */
	public function assertOwned(Document $doc, string $userId): void {
		if ($doc->getUserId() !== $userId) {
			throw new ForbiddenException('You do not own this document.');
		}
	}

	/**
	 * Load a document by id and assert ownership.
	 *
	 * @throws NotFoundException if the document does not exist
	 * @throws ForbiddenException if the document belongs to another user
	 */
	private function findOwnedDocument(string $userId, int $documentId): Document {
		try {
			$doc = $this->documentMapper->find($documentId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new NotFoundException('Document not found.', previous: $e);
		}
		$this->assertOwned($doc, $userId);
		return $doc;
	}

	// ─── internals ──────────────────────────────────────────────────────

	/**
	 * Resolve a file id to the concrete file nodes it refers to, expanding
	 * folders to their file children.
	 *
	 * @return list<Node>
	 */
	private function resolveFileNodes(string $userId, int $fileId): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileId);
		if (empty($nodes)) {
			throw new FilesNotFoundException('File ' . $fileId . ' not found for user ' . $userId);
		}

		$files = [];
		foreach ($nodes as $node) {
			if ($node instanceof Folder) {
				foreach ($node->getDirectoryListing() as $child) {
					if ($child instanceof Folder) {
						// Recurse one level is rarely needed; the file picker
						// already expands shallow folders. Deeper recursion is
						// handled by the picker, but we support a single level
						// of contained folders for safety.
						foreach ($child->getDirectoryListing() as $grandchild) {
							if (!($grandchild instanceof Folder)) {
								$files[] = $grandchild;
							}
						}
					} else {
						$files[] = $child;
					}
				}
			} else {
				$files[] = $node;
			}
		}

		if (empty($files)) {
			throw new FilesNotFoundException('Folder ' . $fileId . ' contains no files.');
		}
		return $files;
	}

	/**
	 * Create a Document row for a file node and schedule OCR (or park it with
	 * an error when no OCR provider is enabled).
	 */
	private function createAndSchedule(string $userId, Node $node): Document {
		$now = $this->now();

		$doc = new Document();
		$doc->setUserId($userId);
		$doc->setFileId($node->getId());
		$doc->setFilePath($node->getPath());
		$doc->setFileName($node->getName());
		$doc->setMimeType($node->getMimetype());
		$doc->setStatus(Document::STATUS_PENDING);
		$doc->setCreatedAt($now);
		$doc->setUpdatedAt($now);
		$this->documentMapper->insert($doc);

		if (!$this->providerHasOcr()) {
			// No provider yet — leave PENDING with an actionable hint so the
			// user can enable one and hit re-process.
			$doc->setErrorMessage('No OCR provider enabled');
			$doc->setUpdatedAt($this->now());
			$this->documentMapper->update($doc);
			return $doc;
		}

		$this->scheduleOcr($doc);
		return $doc;
	}

	/**
	 * Schedule the OCR task for a document, store the task id and flip status
	 * to OCR_RUNNING. On scheduling failure, mark the document FAILED.
	 */
	private function scheduleOcr(Document $doc): void {
		if (!$this->providerHasOcr()) {
			$doc->setStatus(Document::STATUS_PENDING);
			$doc->setErrorMessage('No OCR provider enabled');
			$doc->setUpdatedAt($this->now());
			$this->documentMapper->update($doc);
			return;
		}

		try {
			$task = new Task(
				self::OCR_TASK_TYPE_ID,
				['input' => [$doc->getFileId()]],
				ConfigService::APP_ID,
				$doc->getUserId(),
				(string)$doc->getId()
			);
			$this->taskManager->scheduleTask($task);
			$doc->setOcrTaskId($task->getId());
			$doc->setStatus(Document::STATUS_OCR_RUNNING);
			$doc->setErrorMessage(null);
			$doc->setUpdatedAt($this->now());
			$this->documentMapper->update($doc);
		} catch (TaskProcessingException $e) {
			$this->logger->warning('Scheduling OCR failed for document {id}: {msg}', [
				'id' => $doc->getId(), 'msg' => $e->getMessage(), 'exception' => $e,
			]);
			$doc->setStatus(Document::STATUS_FAILED);
			$doc->setErrorMessage('Failed to schedule OCR: ' . $e->getMessage());
			$doc->setUpdatedAt($this->now());
			$this->documentMapper->update($doc);
		}
	}

	/**
	 * Schedule the LLM extraction task for a document, store the task id and
	 * flip status to EXTRACTION_RUNNING. On scheduling failure, mark FAILED.
	 */
	private function scheduleExtraction(Document $doc, string $ocrText): void {
		if (!$this->providerHasLlm()) {
			$doc->setStatus(Document::STATUS_OCR_DONE);
			$doc->setErrorMessage('No LLM provider enabled');
			$doc->setUpdatedAt($this->now());
			$this->documentMapper->update($doc);
			return;
		}

		try {
			$task = new Task(
				self::LLM_TASK_TYPE_ID,
				['input' => $this->extractionService->buildPrompt($ocrText)],
				ConfigService::APP_ID,
				$doc->getUserId(),
				(string)$doc->getId()
			);
			$this->taskManager->scheduleTask($task);
			$doc->setExtractionTaskId($task->getId());
			$doc->setStatus(Document::STATUS_EXTRACTION_RUNNING);
			$doc->setErrorMessage(null);
			$doc->setUpdatedAt($this->now());
			$this->documentMapper->update($doc);
		} catch (TaskProcessingException $e) {
			$this->logger->warning('Scheduling extraction failed for document {id}: {msg}', [
				'id' => $doc->getId(), 'msg' => $e->getMessage(), 'exception' => $e,
			]);
			$doc->setStatus(Document::STATUS_FAILED);
			$doc->setErrorMessage('Failed to schedule extraction: ' . $e->getMessage());
			$doc->setUpdatedAt($this->now());
			$this->documentMapper->update($doc);
		}
	}

	/**
	 * Persist one TestResult row from a parsed test entry.
	 *
	 * @param array<string,mixed> $entry normalized by ExtractionService::parseExtraction()
	 */
	private function insertTestResult(Report $report, string $userId, array $entry, \DateTime $now): void {
		$valueStr = (string)($entry['value'] ?? '');
		$rangeStr = (string)($entry['ref_range'] ?? '');
		[$refLow, $refHigh] = $this->extractionService->parseRange($rangeStr);

		$result = new TestResult();
		$result->setReportId($report->getId());
		$result->setUserId($userId);
		$result->setTestName((string)($entry['test_name'] ?? ''));
		$result->setValue($valueStr !== '' ? $valueStr : '');
		$result->setValueNumeric($this->extractionService->normalizeValue($valueStr));
		$result->setUnit($this->nullableStr($entry['unit'] ?? null));
		$result->setRefRange($this->nullableStr($rangeStr));
		$result->setRefLow($refLow);
		$result->setRefHigh($refHigh);
		$result->setSeverity($this->mapSeverity((string)($entry['severity'] ?? 'unknown')));
		$result->setSeverityNote($this->nullableStr($entry['severity_note'] ?? null));
		// flags column is a JSON array; we don't currently collect explicit
		// flags from the model, so store null rather than an empty array.
		$result->setFlags(null);
		$result->setTestGroup($this->nullableStr($entry['test_group'] ?? null));
		$result->setCreatedAt($now);
		$this->testResultMapper->insert($result);
	}

	/**
	 * Map a severity string to a TestResult constant value, defaulting to
	 * "unknown" for anything off the allowed set.
	 */
	private function mapSeverity(string $severity): string {
		$severities = [
			TestResult::SEVERITY_NORMAL, TestResult::SEVERITY_LOW, TestResult::SEVERITY_HIGH,
			TestResult::SEVERITY_MILD, TestResult::SEVERITY_CAUTIOUS, TestResult::SEVERITY_SEVERE,
			TestResult::SEVERITY_ABNORMAL, TestResult::SEVERITY_UNKNOWN,
		];
		$lower = strtolower($severity);
		return in_array($lower, $severities, true) ? $lower : TestResult::SEVERITY_UNKNOWN;
	}

	private function providerHasOcr(): bool {
		return in_array(self::OCR_TASK_TYPE_ID, $this->taskManager->getAvailableTaskTypeIds(), true);
	}

	private function providerHasLlm(): bool {
		return in_array(self::LLM_TASK_TYPE_ID, $this->taskManager->getAvailableTaskTypeIds(), true);
	}

	private function now(): \DateTime {
		return \DateTime::createFromImmutable(
			(new \DateTimeImmutable('@' . $this->timeFactory->getTime()))->setTimezone(new \DateTimeZone('UTC'))
		);
	}

	private function nullableStr(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		$s = is_string($value) ? $value : (string)$value;
		return $s !== '' ? $s : null;
	}

	/**
	 * Build a FAILED Document row for a file id that could not be resolved or
	 * scheduled, so the batch result still surfaces the failure to the user.
	 */
	private function createFailedRow(string $userId, int $fileId, string $message): Document {
		$now = $this->now();
		$doc = new Document();
		$doc->setUserId($userId);
		$doc->setFileId($fileId);
		$doc->setFilePath('');
		$doc->setFileName('');
		$doc->setMimeType('');
		$doc->setStatus(Document::STATUS_FAILED);
		$doc->setErrorMessage($message);
		$doc->setCreatedAt($now);
		$doc->setUpdatedAt($now);
		$this->documentMapper->insert($doc);
		return $doc;
	}
}

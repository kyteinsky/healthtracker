<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Tests\Unit\Service;

use OCA\HealthTracker\Db\Document;
use OCA\HealthTracker\Db\DocumentMapper;
use OCA\HealthTracker\Db\Report;
use OCA\HealthTracker\Db\ReportMapper;
use OCA\HealthTracker\Db\TestResult;
use OCA\HealthTracker\Db\TestResultMapper;
use OCA\HealthTracker\Exception\ForbiddenException;
use OCA\HealthTracker\Exception\NotFoundException;
use OCA\HealthTracker\Service\ConfigService;
use OCA\HealthTracker\Service\DocumentService;
use OCA\HealthTracker\Service\ExtractionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for {@see DocumentService} focusing on the post-extraction
 * lifecycle handlers and ownership enforcement. The TaskProcessing scheduling
 * path is exercised through {@see testAddFilesParksDocumentWhenNoOcrProvider}.
 */
class DocumentServiceTest extends TestCase {
	private DocumentMapper&MockObject $documentMapper;
	private ReportMapper&MockObject $reportMapper;
	private TestResultMapper&MockObject $testResultMapper;
	private IManager&MockObject $taskManager;
	private ConfigService&MockObject $configService;
	private ExtractionService&MockObject $extractionService;
	private IRootFolder&MockObject $rootFolder;
	private IDBConnection&MockObject $db;
	private LoggerInterface&MockObject $logger;
	private ITimeFactory&MockObject $timeFactory;
	private DocumentService $service;

	/** @var list<Report> reports inserted during the last atomic() call */
	private array $insertedReports = [];
	/** @var list<TestResult> test results inserted during the last atomic() call */
	private array $insertedResults = [];

	protected function setUp(): void {
		parent::setUp();
		$this->documentMapper = $this->createMock(DocumentMapper::class);
		$this->reportMapper = $this->createMock(ReportMapper::class);
		$this->testResultMapper = $this->createMock(TestResultMapper::class);
		$this->taskManager = $this->createMock(IManager::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->extractionService = $this->createMock(ExtractionService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		// Freeze time so assertions on timestamps are deterministic.
		$this->timeFactory->method('getTime')->willReturn(1700000000);

		// Capture the closure passed to atomic() and run it immediately so the
		// service's transactional body executes in the test. The TTransactional
		// trait calls $this->atomic($fn, $db); we make $db->beginTransaction a
		// no-op and commit likewise.
		$this->db->method('beginTransaction')->willReturn(null);
		$this->db->method('commit')->willReturn(null);

		// Record inserted reports/results so we can assert on their fields.
		$this->reportMapper
			->method('insert')
			->willReturnCallback(function (Report $r): Report {
				$r->setId(count($this->insertedReports) + 1);
				$this->insertedReports[] = $r;
				return $r;
			});
		$this->testResultMapper
			->method('insert')
			->willReturnCallback(function (TestResult $t): TestResult {
				$t->setId(count($this->insertedResults) + 1);
				$this->insertedResults[] = $t;
				return $t;
			});

		$this->service = new DocumentService(
			$this->documentMapper,
			$this->reportMapper,
			$this->testResultMapper,
			$this->taskManager,
			$this->configService,
			$this->extractionService,
			$this->rootFolder,
			$this->db,
			$this->logger,
			$this->timeFactory,
		);
	}

	public function testHandleExtractionSuccessCreatesReportAndTestResults(): void {
		$doc = $this->makeDocument(7, 'alice');
		$this->documentMapper->method('find')->with(7)->willReturn($doc);

		$this->extractionService
			->method('parseExtraction')
			->willReturn([
				'patient_name' => 'Jane Doe',
				'test_date' => '2026-01-15',
				'report_date' => '2026-01-20',
				'doctor_name' => 'Dr. Smith',
				'lab_name' => 'CityLab',
				'report_type' => 'CBC',
				'notes' => 'Routine',
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
					[
						'test_name' => 'Platelets',
						'value' => '>450',
						'unit' => '10^3/µL',
						'ref_range' => '150-450',
						'severity' => 'high',
						'severity_note' => 'Above upper bound',
						'test_group' => 'Hematology',
					],
				],
			]);
		$this->extractionService->method('normalizeValue')->willReturnMap([
			['14.2', 14.2],
			['>450', 450.0],
		]);
		$this->extractionService->method('parseRange')->willReturnMap([
			['12.0-16.0', [12.0, 16.0]],
			['150-450', [150.0, 450.0]],
		]);

		// Capture the document update to assert the status flip to DONE.
		$updatedDoc = null;
		$this->documentMapper
			->method('update')
			->willReturnCallback(function (Document $d) use (&$updatedDoc): Document {
				$updatedDoc = $d;
				return $d;
			});

		$this->service->handleExtractionSuccess(7, ['output' => '{}']);

		// One report, two test results.
		$this->assertCount(1, $this->insertedReports);
		$this->assertCount(2, $this->insertedResults);

		$report = $this->insertedReports[0];
		$this->assertSame('alice', $report->getUserId());
		$this->assertSame(7, $report->getDocumentId());
		$this->assertSame('Jane Doe', $report->getPatientName());
		$this->assertSame('2026-01-15', $report->getTestDate());
		$this->assertSame('CityLab', $report->getLabName());

		// First test result: Hemoglobin, normal.
		$this->assertSame('Hemoglobin', $this->insertedResults[0]->getTestName());
		$this->assertSame('14.2', $this->insertedResults[0]->getValue());
		$this->assertSame(14.2, $this->insertedResults[0]->getValueNumeric());
		$this->assertSame(12.0, $this->insertedResults[0]->getRefLow());
		$this->assertSame(16.0, $this->insertedResults[0]->getRefHigh());
		$this->assertSame(TestResult::SEVERITY_NORMAL, $this->insertedResults[0]->getSeverity());

		// Second test result: Platelets, high severity.
		$this->assertSame('Platelets', $this->insertedResults[1]->getTestName());
		$this->assertSame('>450', $this->insertedResults[1]->getValue());
		$this->assertSame(TestResult::SEVERITY_HIGH, $this->insertedResults[1]->getSeverity());
		$this->assertSame('Above upper bound', $this->insertedResults[1]->getSeverityNote());

		// The document must have been flipped to DONE with a processed_at set.
		$this->assertNotNull($updatedDoc);
		$this->assertSame(Document::STATUS_DONE, $updatedDoc->getStatus());
		$this->assertNotNull($updatedDoc->getProcessedAt());
	}

	public function testHandleExtractionSuccessFallsBackToFailureOnUnparseableOutput(): void {
		$doc = $this->makeDocument(9, 'alice');
		$this->documentMapper->method('find')->with(9)->willReturn($doc);
		$this->extractionService
			->method('parseExtraction')
			->willThrowException(new \OCA\HealthTracker\Exception\ValidationException('bad json'));

		$updatedDoc = null;
		$this->documentMapper
			->method('update')
			->willReturnCallback(function (Document $d) use (&$updatedDoc): Document {
				$updatedDoc = $d;
				return $d;
			});

		$this->service->handleExtractionSuccess(9, ['output' => 'not json']);

		// No report or test results should be created on a parse failure.
		$this->assertCount(0, $this->insertedReports);
		$this->assertCount(0, $this->insertedResults);
		$this->assertNotNull($updatedDoc);
		$this->assertSame(Document::STATUS_FAILED, $updatedDoc->getStatus());
		$this->assertStringContainsString('Could not parse extraction output', $updatedDoc->getErrorMessage());
	}

	public function testHandleFailureMarksOcrFailedForOcrStage(): void {
		$doc = $this->makeDocument(3, 'bob');
		$this->documentMapper->method('find')->with(3)->willReturn($doc);

		$updatedDoc = null;
		$this->documentMapper
			->method('update')
			->willReturnCallback(function (Document $d) use (&$updatedDoc): Document {
				$updatedDoc = $d;
				return $d;
			});

		$this->service->handleFailure(3, 'provider timed out', 'ocr');

		$this->assertSame(Document::STATUS_OCR_FAILED, $updatedDoc->getStatus());
		$this->assertSame('provider timed out', $updatedDoc->getErrorMessage());
	}

	public function testHandleFailureMarksFailedForExtractionStage(): void {
		$doc = $this->makeDocument(3, 'bob');
		$this->documentMapper->method('find')->with(3)->willReturn($doc);

		$updatedDoc = null;
		$this->documentMapper
			->method('update')
			->willReturnCallback(function (Document $d) use (&$updatedDoc): Document {
				$updatedDoc = $d;
				return $d;
			});

		$this->service->handleFailure(3, 'model error', 'extraction');

		$this->assertSame(Document::STATUS_FAILED, $updatedDoc->getStatus());
	}

	public function testHandleFailureIsNoOpWhenDocumentMissing(): void {
		$this->documentMapper
			->method('find')
			->willThrowException(new DoesNotExistException('gone'));
		// No exception should bubble; the update mock is never called.
		$this->documentMapper->expects($this->never())->method('update');
		$this->service->handleFailure(999, 'err', 'ocr');
	}

	public function testGetDocumentThrowsForbiddenForForeignOwner(): void {
		$doc = $this->makeDocument(1, 'alice');
		$this->documentMapper->method('find')->with(1)->willReturn($doc);

		$this->expectException(ForbiddenException::class);
		$this->service->getDocument('bob', 1);
	}

	public function testGetDocumentThrowsNotFoundWhenMissing(): void {
		$this->documentMapper
			->method('find')
			->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(NotFoundException::class);
		$this->service->getDocument('alice', 1);
	}

	public function testDeleteDocumentCascadesReportsAndResults(): void {
		$doc = $this->makeDocument(5, 'alice');
		$this->documentMapper->method('find')->with(5)->willReturn($doc);

		$report = new Report();
		$report->setId(11);
		$report->setUserId('alice');
		$report->setDocumentId(5);
		$this->reportMapper->method('findByDocumentId')->with(5)->willReturn([$report]);

		$result = new TestResult();
		$result->setId(21);
		$result->setReportId(11);
		$result->setTestName('X');
		$result->setValue('1');
		$this->testResultMapper->method('findByReportId')->with(11)->willReturn([$result]);

		// All three deletes must happen, in cascade order: result → report → document.
		$this->testResultMapper->expects($this->once())->method('delete')->with($this->callback(fn ($t) => $t->getId() === 21));
		$this->reportMapper->expects($this->once())->method('delete')->with($this->callback(fn ($r) => $r->getId() === 11));
		$this->documentMapper->expects($this->once())->method('delete')->with($this->callback(fn ($d) => $d->getId() === 5));

		$this->service->deleteDocument('alice', 5);
	}

	public function testDeleteDocumentThrowsForbiddenForForeignOwner(): void {
		$doc = $this->makeDocument(5, 'alice');
		$this->documentMapper->method('find')->with(5)->willReturn($doc);

		$this->expectException(ForbiddenException::class);
		$this->service->deleteDocument('bob', 5);
	}

	public function testAddFilesParksDocumentWhenNoOcrProvider(): void {
		// No OCR provider: getAvailableTaskTypeIds returns an empty list.
		$this->taskManager->method('getAvailableTaskTypeIds')->willReturn([]);
		$this->taskManager->expects($this->never())->method('scheduleTask');

		// A single file node to add.
		$file = $this->createMock(\OCP\Files\File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('report.pdf');
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getPath')->willReturn('/alice/files/report.pdf');

		$userFolder = $this->createMock(\OCP\Files\Folder::class);
		$userFolder->method('getById')->with(42)->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

		$created = null;
		$this->documentMapper
			->method('insert')
			->willReturnCallback(function (Document $d) use (&$created): Document {
				$d->setId(1);
				$created = $d;
				return $d;
			});
		$this->documentMapper
			->method('update')
			->willReturnCallback(function (Document $d): Document { return $d; });

		$result = $this->service->addFiles('alice', [42]);

		$this->assertCount(1, $result);
		$this->assertSame(Document::STATUS_PENDING, $created->getStatus());
		$this->assertSame('No OCR provider enabled', $created->getErrorMessage());
		$this->assertSame('report.pdf', $created->getFileName());
	}

	private function makeDocument(int $id, string $userId): Document {
		$doc = new Document();
		$doc->setId($id);
		$doc->setUserId($userId);
		$doc->setFileId($id * 10);
		$doc->setFilePath('/' . $userId . '/files/report.pdf');
		$doc->setFileName('report.pdf');
		$doc->setMimeType('application/pdf');
		$doc->setStatus(Document::STATUS_EXTRACTION_RUNNING);
		return $doc;
	}
}

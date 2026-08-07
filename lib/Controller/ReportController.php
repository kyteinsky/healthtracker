<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Controller;

use OCA\HealthTracker\Db\ReportMapper;
use OCA\HealthTracker\Db\TestResultMapper;
use OCA\HealthTracker\Exception\ForbiddenException;
use OCA\HealthTracker\Exception\NotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Db\Exception as DbException;
use OCP\IRequest;

class ReportController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private ReportMapper $reportMapper,
		private TestResultMapper $testResultMapper,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List the current user's reports, newest first, optionally date-filtered.
	 */
	#[NoAdminRequired]
	public function index(?string $from = null, ?string $to = null, int $limit = 200): DataResponse {
		return $this->handle(fn () => array_map(
			static fn ($r) => $r->jsonSerialize(),
			$this->reportMapper->findFiltered((string)$this->userId, $from, $to, $limit),
		));
	}

	/**
	 * Show a single report with its nested test results.
	 */
	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$report = $this->loadOwnedReport($id);
			$data = $report->jsonSerialize();
			$data['tests'] = array_map(
				static fn ($t) => $t->jsonSerialize(),
				$this->testResultMapper->findByReportId($id),
			);
			return $data;
		});
	}

	/**
	 * Update editable fields on a report (user edits after extraction).
	 */
	#[NoAdminRequired]
	public function update(
		int $id,
		?string $patientName = null,
		?string $testDate = null,
		?string $doctorName = null,
		?string $labName = null,
		?string $notes = null,
	): DataResponse {
		return $this->handle(function () use ($id, $patientName, $testDate, $doctorName, $labName, $notes) {
			$report = $this->loadOwnedReport($id);

			// Only mutate fields the client actually sent (null = not provided). We
			// cannot distinguish "clear to null" from "omit" here, but the SPA sends
			// the full edited object so this matches the user's intent.
			$report->setPatientName($patientName);
			$report->setTestDate($testDate);
			$report->setDoctorName($doctorName);
			$report->setLabName($labName);
			$report->setNotes($notes);

			$this->reportMapper->update($report);
			return $report->jsonSerialize();
		});
	}

	/**
	 * Delete a report and all of its test results.
	 */
	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			// loadOwnedReport enforces ownership (404 on missing, 403 on foreign).
			$this->loadOwnedReport($id);

			// Cascade test results: no bulk delete helper, so delete each row.
			foreach ($this->testResultMapper->findByReportId($id) as $testResult) {
				$this->testResultMapper->delete($testResult);
			}

			$this->reportMapper->deleteById($id);
			return ['id' => $id];
		});
	}

	/**
	 * Return just the test results for a report (without the report envelope).
	 */
	#[NoAdminRequired]
	public function tests(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->loadOwnedReport($id);
			return array_map(
				static fn ($t) => $t->jsonSerialize(),
				$this->testResultMapper->findByReportId($id),
			);
		});
	}

	/**
	 * Flat chronological list of test results for the table view.
	 */
	#[NoAdminRequired]
	public function results(?string $testName = null, ?string $from = null, ?string $to = null): DataResponse {
		// findResultsFlat already returns assoc arrays with camelCase keys.
		return $this->handle(fn () => $this->testResultMapper->findResultsFlat((string)$this->userId, $testName, $from, $to));
	}

	/**
	 * Per-test trend series (oldest first).
	 */
	#[NoAdminRequired]
	public function trend(string $testName): DataResponse {
		// findTrend returns assoc arrays with the contract trend keys (date, value,
		// valueNumeric, unit, refLow, refHigh, severity, reportId), oldest first.
		return $this->handle(fn () => $this->testResultMapper->findTrend((string)$this->userId, $testName));
	}

	/**
	 * Distinct test names the current user has (for the trend picker).
	 */
	#[NoAdminRequired]
	public function testNames(): DataResponse {
		return $this->handle(fn () => $this->testResultMapper->findTestNames((string)$this->userId));
	}

	/**
	 * Load a report and verify the current user owns it.
	 *
	 * @throws NotFoundException  report does not exist
	 * @throws ForbiddenException report belongs to another user
	 */
	private function loadOwnedReport(int $id) {
		try {
			$report = $this->reportMapper->find($id);
		} catch (\OCP\Db\DoesNotExistException $e) {
			throw new NotFoundException('Report not found');
		} catch (DbException $e) {
			throw new NotFoundException('Report not found');
		}

		if ($report->getUserId() !== $this->userId) {
			throw new ForbiddenException('Report not found');
		}

		return $report;
	}
}

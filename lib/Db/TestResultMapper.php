<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TestResult>
 */
class TestResultMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'healthtracker_test_results', TestResult::class);
	}

	/**
	 * All test results belonging to a report.
	 *
	 * @return TestResult[]
	 */
	public function findByReportId(int $reportId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('report_id', $qb->createNamedParameter($reportId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Test results for a user, optionally narrowed to a test name and a date
	 * range on the parent report's test_date. Ordered by report test_date DESC
	 * via a JOIN to healthtracker_reports.
	 *
	 * @return TestResult[]
	 */
	public function findForUser(string $userId, ?string $testName = null, ?string $from = null, ?string $to = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.*')
			->from($this->getTableName(), 't')
			->leftJoin('t', 'healthtracker_reports', 'r', $qb->expr()->eq('t.report_id', 'r.id'))
			->where($qb->expr()->eq('t.user_id', $qb->createNamedParameter($userId)));
		if ($testName !== null && $testName !== '') {
			$qb->andWhere($qb->expr()->eq('t.test_name', $qb->createNamedParameter($testName)));
		}
		if ($from !== null && $from !== '') {
			$qb->andWhere($qb->expr()->gte('r.test_date', $qb->createNamedParameter($from)));
		}
		if ($to !== null && $to !== '') {
			$qb->andWhere($qb->expr()->lte('r.test_date', $qb->createNamedParameter($to)));
		}
		$qb->orderBy('r.test_date', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Trend for a single test across all of a user's reports, oldest first.
	 * refLow/refHigh come from the test row itself; the date comes from the
	 * joined report. Returns associative arrays matching the contract trend
	 * endpoint shape ({ date, value, valueNumeric, unit, refLow, refHigh,
	 * severity, reportId }) — entities cannot carry the report's test_date, so
	 * the join is materialised here.
	 *
	 * @return array<int, array{
	 *   date: string|null,
	 *   value: string,
	 *   valueNumeric: float|null,
	 *   unit: string|null,
	 *   refLow: float|null,
	 *   refHigh: float|null,
	 *   severity: string,
	 *   reportId: int
	 * }>
	 */
	public function findTrend(string $userId, string $testName): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.report_id', 'r.test_date', 't.value', 't.value_numeric', 't.unit', 't.ref_low', 't.ref_high', 't.severity')
			->from($this->getTableName(), 't')
			->leftJoin('t', 'healthtracker_reports', 'r', $qb->expr()->eq('t.report_id', 'r.id'))
			->where($qb->expr()->eq('t.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('t.test_name', $qb->createNamedParameter($testName)))
			->orderBy('r.test_date', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		$trend = [];
		foreach ($rows as $row) {
			$trend[] = [
				'date' => isset($row['test_date']) && $row['test_date'] !== null ? (string)$row['test_date'] : null,
				'value' => (string)$row['value'],
				'valueNumeric' => isset($row['value_numeric']) && $row['value_numeric'] !== null ? (float)$row['value_numeric'] : null,
				'unit' => isset($row['unit']) && $row['unit'] !== null ? (string)$row['unit'] : null,
				'refLow' => isset($row['ref_low']) && $row['ref_low'] !== null ? (float)$row['ref_low'] : null,
				'refHigh' => isset($row['ref_high']) && $row['ref_high'] !== null ? (float)$row['ref_high'] : null,
				'severity' => (string)$row['severity'],
				'reportId' => (int)$row['report_id'],
			];
		}
		return $trend;
	}

	/**
	 * Distinct test names a user has results for (for the trend picker).
	 *
	 * @return string[]
	 */
	public function findTestNames(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('test_name')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('test_name', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		$names = [];
		foreach ($rows as $row) {
			$names[] = (string)$row['test_name'];
		}
		return $names;
	}

	/**
	 * Flat, chronological list of test results joined with their report's
	 * test_date — for the results table endpoint. Returns associative arrays
	 * (joined rows cannot be hydrated into a single entity).
	 *
	 * @return array<int, array{
	 *   reportId: int,
	 *   testDate: string|null,
	 *   reportDate: string|null,
	 *   patientName: string|null,
	 *   labName: string|null,
	 *   testName: string,
	 *   value: string,
	 *   valueNumeric: float|null,
	 *   unit: string|null,
	 *   refRange: string|null,
	 *   refLow: float|null,
	 *   refHigh: float|null,
	 *   severity: string,
	 *   severityNote: string|null,
	 *   testGroup: string|null
	 * }>
	 */
	public function findResultsFlat(string $userId, ?string $testName = null, ?string $from = null, ?string $to = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id', 't.report_id', 'r.test_date', 'r.report_date', 'r.patient_name', 'r.lab_name', 't.test_name', 't.value', 't.value_numeric', 't.unit', 't.ref_range', 't.ref_low', 't.ref_high', 't.severity', 't.severity_note', 't.test_group')
			->from($this->getTableName(), 't')
			->leftJoin('t', 'healthtracker_reports', 'r', $qb->expr()->eq('t.report_id', 'r.id'))
			->where($qb->expr()->eq('t.user_id', $qb->createNamedParameter($userId)));
		if ($testName !== null && $testName !== '') {
			$qb->andWhere($qb->expr()->eq('t.test_name', $qb->createNamedParameter($testName)));
		}
		if ($from !== null && $from !== '') {
			$qb->andWhere($qb->expr()->gte('r.test_date', $qb->createNamedParameter($from)));
		}
		if ($to !== null && $to !== '') {
			$qb->andWhere($qb->expr()->lte('r.test_date', $qb->createNamedParameter($to)));
		}
		$qb->orderBy('r.test_date', 'DESC')
			->addOrderBy('t.test_name', 'ASC');

		$rows = $qb->executeQuery()->fetchAll();
		$flat = [];
		foreach ($rows as $row) {
			$flat[] = [
				'reportId' => (int)$row['report_id'],
				'testDate' => isset($row['test_date']) ? (string)$row['test_date'] : null,
				'reportDate' => isset($row['report_date']) && $row['report_date'] !== null ? (string)$row['report_date'] : null,
				'patientName' => isset($row['patient_name']) && $row['patient_name'] !== null ? (string)$row['patient_name'] : null,
				'labName' => isset($row['lab_name']) && $row['lab_name'] !== null ? (string)$row['lab_name'] : null,
				'testName' => (string)$row['test_name'],
				'value' => (string)$row['value'],
				'valueNumeric' => isset($row['value_numeric']) && $row['value_numeric'] !== null ? (float)$row['value_numeric'] : null,
				'unit' => isset($row['unit']) && $row['unit'] !== null ? (string)$row['unit'] : null,
				'refRange' => isset($row['ref_range']) && $row['ref_range'] !== null ? (string)$row['ref_range'] : null,
				'refLow' => isset($row['ref_low']) && $row['ref_low'] !== null ? (float)$row['ref_low'] : null,
				'refHigh' => isset($row['ref_high']) && $row['ref_high'] !== null ? (float)$row['ref_high'] : null,
				'severity' => (string)$row['severity'],
				'severityNote' => isset($row['severity_note']) && $row['severity_note'] !== null ? (string)$row['severity_note'] : null,
				'testGroup' => isset($row['test_group']) && $row['test_group'] !== null ? (string)$row['test_group'] : null,
			];
		}
		return $flat;
	}
}

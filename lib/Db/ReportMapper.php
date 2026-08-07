<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Report>
 */
class ReportMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'healthtracker_reports', Report::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Report {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Reports for a user, optionally filtered by test_date range
	 * ([$from, $to] inclusive on test_date), newest test first.
	 *
	 * @return Report[]
	 */
	public function findFiltered(string $userId, ?string $from = null, ?string $to = null, ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		if ($from !== null && $from !== '') {
			$qb->andWhere($qb->expr()->gte('test_date', $qb->createNamedParameter($from)));
		}
		if ($to !== null && $to !== '') {
			$qb->andWhere($qb->expr()->lte('test_date', $qb->createNamedParameter($to)));
		}
		$qb->orderBy('test_date', 'DESC');
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		return $this->findEntities($qb);
	}

	/**
	 * All reports that originated from a given document.
	 *
	 * @return Report[]
	 */
	public function findByDocumentId(int $documentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)))
			->orderBy('test_date', 'DESC');
		return $this->findEntities($qb);
	}
}

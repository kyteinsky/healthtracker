<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema for the HealthTracker app (contract §data model).
 */
class Version1000Date20260710000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// --- healthtracker_documents ---------------------------------------------
		if (!$schema->hasTable('healthtracker_documents')) {
			$table = $schema->createTable('healthtracker_documents');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('file_path', Types::STRING, ['notnull' => true, 'length' => 512]);
			$table->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('mime_type', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'PENDING']);
			$table->addColumn('ocr_task_id', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('extraction_task_id', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('ocr_text', Types::LONGTEXT, ['notnull' => false]);
			$table->addColumn('error_message', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('processed_at', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'status'], 'healthtracker_doc_user_status_idx');
			$table->addIndex(['user_id', 'created_at'], 'healthtracker_doc_user_created_idx');
			$table->addIndex(['file_id'], 'healthtracker_doc_file_idx');
		}

		// --- healthtracker_reports -----------------------------------------------
		if (!$schema->hasTable('healthtracker_reports')) {
			$table = $schema->createTable('healthtracker_reports');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('document_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('patient_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('test_date', Types::DATE, ['notnull' => false]);
			$table->addColumn('report_date', Types::DATE, ['notnull' => false]);
			$table->addColumn('doctor_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('lab_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('report_type', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$table->addColumn('raw_extraction', Types::LONGTEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'test_date'], 'healthtracker_rep_user_date_idx');
			$table->addIndex(['document_id'], 'healthtracker_rep_doc_idx');
		}

		// --- healthtracker_test_results ------------------------------------------
		if (!$schema->hasTable('healthtracker_test_results')) {
			$table = $schema->createTable('healthtracker_test_results');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('report_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('test_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('value', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('value_numeric', Types::DECIMAL, ['notnull' => false, 'precision' => 12, 'scale' => 4]);
			$table->addColumn('unit', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('ref_range', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('ref_low', Types::DECIMAL, ['notnull' => false, 'precision' => 12, 'scale' => 4]);
			$table->addColumn('ref_high', Types::DECIMAL, ['notnull' => false, 'precision' => 12, 'scale' => 4]);
			$table->addColumn('severity', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'normal']);
			$table->addColumn('severity_note', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('flags', Types::TEXT, ['notnull' => false]);
			$table->addColumn('test_group', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['report_id'], 'healthtracker_res_report_idx');
			$table->addIndex(['user_id', 'test_name'], 'healthtracker_res_user_name_idx');
			$table->addIndex(['user_id', 'test_group'], 'healthtracker_res_user_group_idx');
		}

		return $schema;
	}
}

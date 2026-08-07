<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Listener;

use OCA\HealthTracker\AppInfo\Application;
use OCA\HealthTracker\Service\DocumentService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\AbstractTaskProcessingEvent;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use OCP\TaskProcessing\TaskTypes\ImageToTextOpticalCharacterRecognition;
use OCP\TaskProcessing\TaskTypes\TextToText;

/**
 * Listens for TaskProcessing completion events for tasks scheduled by this app
 * (appId === 'healthtracker'). Routes OCR vs. LLM-extraction outcomes to the
 * DocumentService. The task's customId is the healthtracker_documents.id.
 *
 * @implements IEventListener<Event>
 */
class TaskProcessingListener implements IEventListener {
	public function __construct(
		private DocumentService $documentService,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof AbstractTaskProcessingEvent) {
			return;
		}

		$task = $event->getTask();
		if ($task->getAppId() !== Application::APP_ID) {
			return;
		}

		$documentId = (int)$task->getCustomId();
		if ($documentId <= 0) {
			return;
		}

		$taskTypeId = $task->getTaskTypeId();

		if ($event instanceof TaskSuccessfulEvent) {
			$output = $task->getOutput() ?? [];

			if ($taskTypeId === ImageToTextOpticalCharacterRecognition::ID) {
				$this->documentService->handleOcrSuccess($documentId, $output);
			} elseif ($taskTypeId === TextToText::ID) {
				$this->documentService->handleExtractionSuccess($documentId, $output);
			}
			return;
		}

		if ($event instanceof TaskFailedEvent) {
			$errorMessage = $event->getErrorMessage() ?? 'Task processing failed';
			$stage = $taskTypeId === ImageToTextOpticalCharacterRecognition::ID ? 'ocr' : 'extraction';
			$this->documentService->handleFailure($documentId, $errorMessage, $stage);
		}
	}
}

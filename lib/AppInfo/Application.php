<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\AppInfo;

use OCA\HealthTracker\ConfigLexicon;
use OCA\HealthTracker\Listener\TaskProcessingListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'healthtracker';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// TaskProcessing is async: we learn about OCR/extraction outcomes through
		// these events, filtered by appId in the listener.
		$context->registerEventListener(TaskSuccessfulEvent::class, TaskProcessingListener::class);
		$context->registerEventListener(TaskFailedEvent::class, TaskProcessingListener::class);

		// Register the config lexicon if the services layer ships one.
		if (class_exists(ConfigLexicon::class)) {
			$context->registerConfigLexicon(ConfigLexicon::class);
		}
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}

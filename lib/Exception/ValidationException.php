<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Exception;

use OCP\AppFramework\Http;

/**
 * Thrown when input fails domain-level validation (e.g. malformed extraction
 * JSON, out-of-range values). Maps to HTTP 422 Unprocessable Entity.
 */
class ValidationException extends HealthTrackerException {
	public function __construct(string $message = 'Validation failed') {
		parent::__construct($message);
	}

	#[\Override]
	public function getHttpStatus(): int {
		return Http::STATUS_UNPROCESSABLE_ENTITY;
	}
}

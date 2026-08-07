<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Exception;

use OCP\AppFramework\Http;

/**
 * Base for domain errors that map cleanly onto HTTP status codes.
 *
 * Controllers using {@see \OCA\HealthTracker\Controller\ApiControllerTrait} turn
 * these into JSON error responses via {@see self::getHttpStatus()}.
 */
class HealthTrackerException extends \Exception {
	public function getHttpStatus(): int {
		return Http::STATUS_BAD_REQUEST;
	}
}

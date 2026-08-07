<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthTracker\Controller;

use OCA\HealthTracker\Service\DocumentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;

class DocumentController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private DocumentService $documentService,
		private IRootFolder $rootFolder,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List the current user's documents, optionally filtered by status.
	 */
	#[NoAdminRequired]
	public function index(?string $status = null, int $limit = 200, int $offset = 0): DataResponse {
		// DocumentService returns an array of already-jsonSerialize'd document arrays.
		return $this->handle(fn () => $this->documentService->getDocuments((string)$this->userId, $status, $limit, $offset));
	}

	/**
	 * Create document rows for the given file ids (or resolve paths to ids)
	 * and kick off OCR processing for each.
	 */
	#[NoAdminRequired]
	public function create(array $fileIds = [], array $paths = []): DataResponse {
		return $this->handle(function () use ($fileIds, $paths) {
			$ids = $fileIds;
			if ($ids === [] && $paths !== []) {
				$userFolder = $this->rootFolder->getUserFolder((string)$this->userId);
				foreach ($paths as $path) {
					try {
						$node = $userFolder->get($path);
						$ids[] = $node->getId();
					} catch (NotFoundException $e) {
						// Skip paths that no longer resolve; the SPA reports missing files.
					}
				}
			}
			$ids = array_map('intval', $ids);
			// DocumentService::addFiles returns an array of already-jsonSerialize'd document arrays.
			return $this->documentService->addFiles((string)$this->userId, $ids);
		});
	}

	/**
	 * Show a single document.
	 */
	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		// DocumentService::getDocument returns a jsonSerialize'd document array (with ownership enforced).
		return $this->handle(fn () => $this->documentService->getDocument((string)$this->userId, $id));
	}

	/**
	 * Delete a document (and its derived report/tests, cascaded by the service).
	 */
	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->documentService->deleteDocument((string)$this->userId, $id);
			return ['id' => $id];
		});
	}

	/**
	 * Re-queue a document's processing (e.g. after an admin enabled a provider).
	 */
	#[NoAdminRequired]
	public function process(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->documentService->processDocument((string)$this->userId, $id);
			return ['id' => $id];
		});
	}

	/**
	 * Return the cached OCR text for a document (null until OCR completes).
	 */
	#[NoAdminRequired]
	public function ocrText(int $id): DataResponse {
		return $this->handle(fn () => [
			'ocrText' => $this->documentService->getOcrText((string)$this->userId, $id),
		]);
	}
}

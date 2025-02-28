<?php
/*
 * Copyright (c) 2021. The Nextcloud Recognize contributors.
 *
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */

namespace OCA\Recognize\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

class FileFinderService {
	/**
	 * @var LoggerInterface
	 */
	private $logger;

	private ISystemTagObjectMapper $objectMapper;

	private ISystemTag $recognizedTag;

	/**
	 * @var string[] $formats
	 */
	private array $formats;


	private int $maxFileSize = 0;

	/**
	 * @var string[] $ignoreMarkers
	 */
	private array $ignoreMarkers;

	public function __construct(Logger $logger, ISystemTagObjectMapper $objectMapper, TagManager $tagManager) {
		$this->logger = $logger;
		$this->objectMapper = $objectMapper;
		$this->recognizedTag = $tagManager->getProcessedTag();
	}

	/**
	 * @param string[] $formats
	 * @return $this
	 */
	public function setFormats(array $formats):self {
		$this->formats = $formats;
		return $this;
	}

	/**
	 * @param int $fileSize
	 * @return $this
	 */
	public function setMaxFileSize(int $fileSize):self {
		$this->maxFileSize = $fileSize;
		return $this;
	}

	/**
	 * @param string[] $markerFilenames
	 * @return $this
	 */
	public function setIgnoreMarkers(array $markerFilenames):self {
		$this->ignoreMarkers = $markerFilenames;
		return $this;
	}

	/**
	 * @throws NotFoundException|InvalidPathException
	 */
	public function findFilesInFolder(string $user, Folder $folder, &$results = []):array {
		$this->logger->debug('Searching '.$folder->getInternalPath());

		$foundMarkers = array_filter($this->ignoreMarkers, static function ($markerFile) use ($folder) {
			return $folder->nodeExists($markerFile);
		});

		if (count($foundMarkers) > 0) {
			$this->logger->debug('Passing '.$folder->getInternalPath());
			return $results;
		}

		try {
			$nodes = $folder->getDirectoryListing();
		} catch (\Exception $e) {
			$this->logger->debug('Error reading directory '.$folder->getInternalPath().': '.$e->getMessage());
			return $results;
		}
		foreach ($nodes as $node) {
			if ($node instanceof Folder) {
				$this->findFilesInFolder($user, $node, $results);
			} elseif ($node instanceof File) {
				if ($this->objectMapper->haveTag([$node->getId()], 'files', $this->recognizedTag->getId())) {
					$this->logger->debug('Already processed '.$node->getPath());
					continue;
				}
				if ($node->getMountPoint()->getMountType() === 'shared' && $node->getOwner()->getUID() !== $user) {
					$this->logger->debug('Not original owner of '.$node->getPath());
					continue;
				}
				$mimeType = $node->getMimetype();
				if (!in_array($mimeType, $this->formats)) {
					$this->logger->debug('Not a supported format: '.$node->getPath());
					continue;
				}
				if ($this->maxFileSize !== 0 && $this->maxFileSize < $node->getSize()) {
					$this->logger->debug('File is too large: '.$node->getPath());
					continue;
				}
				$this->logger->debug('Found '.$node->getPath());
				$results[] = $node;
			}
		}
		return $results;
	}
}

<?php
/*
 * Copyright (c) 2021. The Nextcloud Recognize contributors.
 *
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */

namespace OCA\Recognize\Classifiers\Images;

use OCA\Recognize\Db\FaceDetection;
use OCA\Recognize\Db\FaceDetectionMapper;
use OCA\Recognize\Service\FaceClusterAnalyzer;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\TagManager;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\IConfig;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

class ClusteringFaceClassifier {
	public const IMAGE_TIMEOUT = 120; // seconds
	public const IMAGE_PUREJS_TIMEOUT = 360; // seconds
	public const MIN_FACE_RECOGNITION_SCORE = 0.5;

	private LoggerInterface $logger;

	private IConfig $config;

	private FaceDetectionMapper $faceDetections;
	private FaceClusterAnalyzer $faceClusterAnalyzer;
	/**
	 * @var \OCA\Recognize\Service\TagManager
	 */
	private TagManager $tagManager;

	public function __construct(Logger $logger, IConfig $config, FaceDetectionMapper $faceDetections, FaceClusterAnalyzer $faceClusterAnalyzer, TagManager $tagManager) {
		$this->logger = $logger;
		$this->config = $config;
		$this->faceDetections = $faceDetections;
		$this->faceClusterAnalyzer = $faceClusterAnalyzer;
		$this->tagManager = $tagManager;
	}

	/**
	 * @param File[] $files
	 * @return void
	 * @throws \OCP\Files\NotFoundException
	 */
	public function classify(string $user, array $files): void {
		$paths = array_map(static function ($file) {
			return $file->getStorage()->getLocalFile($file->getInternalPath());
		}, $files);

		$this->logger->debug('Classifying '.var_export($paths, true));

		$command = [
			$this->config->getAppValue('recognize', 'node_binary'),
			dirname(__DIR__, 3) . '/src/classifier_facevectors.js',
			'-'
		];

		$this->logger->debug('Running '.var_export($command, true));
		$proc = new Process($command, __DIR__);
		if ($this->config->getAppValue('recognize', 'tensorflow.gpu', 'false') === 'true') {
			$proc->setEnv(['RECOGNIZE_GPU' => 'true']);
		}
		if ($this->config->getAppValue('recognize', 'tensorflow.purejs', 'false') === 'true') {
			$proc->setEnv(['RECOGNIZE_PUREJS' => 'true']);
			$proc->setTimeout(count($paths) * self::IMAGE_PUREJS_TIMEOUT);
		} else {
			$proc->setTimeout(count($paths) * self::IMAGE_TIMEOUT);
		}
		$proc->setInput(implode("\n", $paths));
		try {
			$proc->start();

			// Set cores
			$cores = $this->config->getAppValue('recognize', 'tensorflow.cores', '0');
			if ($cores !== '0') {
				@exec('taskset -cp '.implode(',', range(0, (int)$cores, 1)).' ' . $proc->getPid());
			}

			$i = 0;
			$errOut = '';
			$buffer = '';
			foreach ($proc as $type => $data) {
				if ($type !== $proc::OUT) {
					$errOut .= $data;
					$this->logger->debug('Classifier process output: '.$data);
					continue;
				}
				$buffer .= $data;
				$lines = explode("\n", $buffer);
				$buffer = '';
				foreach ($lines as $result) {
					if (trim($result) === '') {
						continue;
					}
					try {
						json_decode($result, true, 512, JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE);
						$invalid = false;
					} catch (\JsonException $e) {
						$invalid = true;
					}
					if ($invalid) {
						$buffer .= "\n".$result;
						continue;
					}
					$this->logger->debug('Result for ' . $files[$i]->getName() . ' = ' . $result);
					try {
						// decode json
						$faces = json_decode($result, true, 512, JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE);

						$this->tagManager->assignTags($files[$i]->getId(), []);

						// remove exisiting detections
						foreach ($this->faceDetections->findByFileId($files[$i]->getId()) as $existingFaceDetection) {
							try {
								$this->faceDetections->delete($existingFaceDetection);
							} catch (Exception $e) {
								$this->logger->debug('Could not delete existing face detection');
							}
						}

						foreach ($faces as $face) {
							if ($face['score'] < self::MIN_FACE_RECOGNITION_SCORE) {
								continue;
							}
							$faceDetection = new FaceDetection();
							$faceDetection->setX($face['x']);
							$faceDetection->setY($face['y']);
							$faceDetection->setWidth($face['width']);
							$faceDetection->setHeight($face['height']);
							$faceDetection->setVector($face['vector']);
							$faceDetection->setFileId($files[$i]->getId());
							$faceDetection->setUserId($files[$i]->getOwner()->getUID());
							$this->faceDetections->insert($faceDetection);
						}
					} catch (InvalidPathException $e) {
						$this->logger->warning('File with invalid path encountered');
					} catch (NotFoundException $e) {
						$this->logger->warning('File to tag was not found');
					} catch (\JsonException $e) {
						$this->logger->warning('JSON exception');
						$this->logger->warning($e->getMessage());
						$this->logger->warning($result);
					} catch (Exception $e) {
						$this->logger->warning('Could not create DB entry for face detection');
						$this->logger->warning($e->getMessage());
					}
					$i++;
				}
			}
			if ($i !== count($files)) {
				$this->logger->warning('Classifier process output: '.$errOut);
				throw new \RuntimeException('Classifier process error');
			}

			$this->faceClusterAnalyzer->findClusters($user);
		} catch (ProcessTimedOutException $e) {
			$this->logger->warning($proc->getErrorOutput());
			throw new \RuntimeException('Classifier process timeout');
		} catch (RuntimeException $e) {
			$this->logger->warning($proc->getErrorOutput());
			throw new \RuntimeException('Classifier process could not be started');
		} catch (\JsonException $e) {
			$this->logger->warning('JSON exception');
			$this->logger->warning($e->getMessage());
			$this->logger->warning($result);
		} catch (Exception $e) {
			$this->logger->warning('Exception during face clustering');
			$this->logger->warning($e->getMessage());
			$this->logger->warning($result);
		}
	}
}

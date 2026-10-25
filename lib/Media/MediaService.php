<?php

declare(strict_types=1);

namespace OCA\Screenings\Media;

use OCA\Screenings\BackgroundJob\ProcessMediaJob;
use OCA\Screenings\Db\Screening;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Security\ISecureRandom;

/**
 * Starts media preparation whenever a screening's source or delivery mode
 * changes. Direct MP4 is checked immediately (it only reads the file's
 * header); HLS packaging and transcoding run in a background job.
 */
class MediaService {
	public function __construct(
		private MediaStorage $storage,
		private Mp4Probe $mp4Probe,
		private IJobList $jobList,
		private IRootFolder $rootFolder,
		private ISecureRandom $random,
	) {
	}

	/**
	 * Resets the screening's media state for its current source and mode.
	 * Mutates $screening; the caller persists it.
	 */
	public function prepare(Screening $screening): void {
		// New screenings (no id yet) have nothing stored.
		if ($screening->getId() !== null) {
			$this->storage->delete($screening->getId(), $screening->getMediaToken());
		}

		$screening->setMediaToken($this->random->generate(16, ISecureRandom::CHAR_ALPHANUMERIC));
		$screening->setMediaProgress(0);
		$screening->setMediaError(null);
		$screening->setMediaWarning(null);
		$screening->setDurationMs(null);

		$file = $this->sourceFile($screening);
		if ($file === null) {
			$screening->setMediaStatus($screening->getSourceFileId() === null ? Screening::MEDIA_NONE : Screening::MEDIA_ERROR);
			if ($screening->getSourceFileId() !== null) {
				$screening->setMediaError('The source file no longer exists');
			}
			return;
		}

		if ($screening->getDeliveryMode() === Screening::MODE_MP4) {
			$this->checkMp4($screening, $file);
			return;
		}

		$screening->setMediaStatus(Screening::MEDIA_PENDING);
	}

	/**
	 * Queues processing for a screening that prepare() left pending. Call
	 * after the screening is saved, so the job sees its token.
	 */
	public function queueIfPending(Screening $screening): void {
		if ($screening->getMediaStatus() === Screening::MEDIA_PENDING) {
			$this->jobList->add(ProcessMediaJob::class, [
				'screeningId' => $screening->getId(),
				'token' => $screening->getMediaToken(),
			]);
		}
	}

	public function deleteMedia(Screening $screening): void {
		$this->storage->delete($screening->getId(), $screening->getMediaToken());
	}

	public function sourceFile(Screening $screening): ?File {
		$fileId = $screening->getSourceFileId();
		if ($fileId === null) {
			return null;
		}
		$node = $this->rootFolder->getUserFolder($screening->getOwnerUid())->getFirstNodeById($fileId);
		return $node instanceof File ? $node : null;
	}

	/**
	 * A local, seekable stream of $file. Remote storages are copied to a
	 * temporary local file first.
	 *
	 * @return resource
	 * @throws MediaException
	 */
	public static function seekableStream(File $file) {
		$stream = $file->fopen('rb');
		if (is_resource($stream) && (stream_get_meta_data($stream)['seekable'] ?? false)) {
			return $stream;
		}
		if (is_resource($stream)) {
			fclose($stream);
		}
		$local = $file->getStorage()->getLocalFile($file->getInternalPath());
		$stream = $local === false ? false : fopen($local, 'rb');
		if (!is_resource($stream)) {
			throw new MediaException('Could not read the video file');
		}
		return $stream;
	}

	private function checkMp4(Screening $screening, File $file): void {
		try {
			$stream = self::seekableStream($file);
			try {
				$info = $this->mp4Probe->probe($stream, $file->getSize());
			} finally {
				fclose($stream);
			}
			if ($info->videoCodec === null) {
				throw new MediaException('This MP4 has no video track');
			}
		} catch (MediaException $e) {
			$screening->setMediaStatus(Screening::MEDIA_ERROR);
			$screening->setMediaError($e->getMessage());
			return;
		}

		$warnings = Mp4Probe::warnings($info);
		$screening->setDurationMs($info->durationMs);
		$screening->setMediaWarning($warnings === [] ? null : implode("\n", $warnings));
		$screening->setMediaProgress(100);
		$screening->setMediaStatus(Screening::MEDIA_READY);
	}
}

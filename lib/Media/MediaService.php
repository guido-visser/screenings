<?php

declare(strict_types=1);

namespace OCA\Screenings\Media;

use OCA\Screenings\BackgroundJob\ProcessMediaJob;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Service\DeliveryModes;
use OCA\Screenings\Service\ScreeningService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Security\ISecureRandom;

/**
 * Starts media preparation whenever a screening's source or delivery mode
 * changes. Direct MP4 is checked immediately (it only reads the file's
 * index), and so is Auto for MP4s that browsers play as they are. HLS
 * packaging and transcoding run in a background job.
 */
class MediaService {
	public function __construct(
		private MediaStorage $storage,
		private Mp4Probe $mp4Probe,
		private DeliveryModes $modes,
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
		$screening->setMediaMode(null);
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
		if ($screening->getDeliveryMode() === Screening::MODE_AUTO) {
			$this->prepareAuto($screening, $file);
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

	/**
	 * Auto: an MP4 every browser plays is served as it is, right away.
	 * Anything else is converted in the background, if this server can.
	 */
	private function prepareAuto(Screening $screening, File $file): void {
		if (in_array($file->getMimetype(), ScreeningService::MP4_MIMES, true)) {
			try {
				$info = $this->probeMp4($file);
			} catch (MediaException) {
				// Not readable as MP4 here; ffmpeg may still manage.
				$info = null;
			}
			if ($info !== null && Mp4Probe::browserSafe($info)) {
				$screening->setMediaMode(Screening::MEDIA_MODE_DIRECT);
				$this->markReady($screening, $info);
				return;
			}
		}
		if (!$this->modes->transcodeEnabled()) {
			$screening->setMediaStatus(Screening::MEDIA_ERROR);
			$screening->setMediaError('Browsers can\'t play this file as it is, and converting it needs server transcoding, which is off. An admin can turn it on under Administration settings → Screenings (it needs ffmpeg).');
			return;
		}
		$screening->setMediaStatus(Screening::MEDIA_PENDING);
	}

	private function checkMp4(Screening $screening, File $file): void {
		try {
			$info = $this->probeMp4($file);
		} catch (MediaException $e) {
			$screening->setMediaStatus(Screening::MEDIA_ERROR);
			$screening->setMediaError($e->getMessage());
			return;
		}
		$warnings = Mp4Probe::warnings($info);
		$screening->setMediaWarning($warnings === [] ? null : implode("\n", $warnings));
		$this->markReady($screening, $info);
	}

	/**
	 * @throws MediaException
	 */
	private function probeMp4(File $file): Mp4Info {
		$stream = self::seekableStream($file);
		try {
			$info = $this->mp4Probe->probe($stream, $file->getSize());
		} finally {
			fclose($stream);
		}
		if ($info->videoCodec === null) {
			throw new MediaException('This MP4 has no video track');
		}
		return $info;
	}

	private function markReady(Screening $screening, Mp4Info $info): void {
		$screening->setDurationMs($info->durationMs);
		$screening->setMediaProgress(100);
		$screening->setMediaStatus(Screening::MEDIA_READY);
	}
}

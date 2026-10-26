<?php

declare(strict_types=1);

namespace OCA\Screenings\Media;

use OCA\Screenings\Db\Screening;
use OCA\Screenings\Db\ScreeningMapper;
use OCA\Screenings\Service\DeliveryModes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Does the work of one processing run (HLS packaging or transcoding). Runs
 * from the background job or `occ screenings:process-media`.
 *
 * Every database write is conditioned on the run's media token: if the owner
 * changed the source meanwhile, the run's results are discarded.
 */
class MediaProcessor {
	/** Progress is written at most this often. */
	private const PROGRESS_INTERVAL_SECONDS = 2;

	public function __construct(
		private ScreeningMapper $mapper,
		private MediaStorage $storage,
		private MediaService $media,
		private HlsPackager $packager,
		private Transcoder $transcoder,
		private Ffmpeg $ffmpeg,
		private DeliveryModes $modes,
		private IRootFolder $rootFolder,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param ?callable(float): void $onProgress also receives progress (for occ)
	 * @return string outcome: "ready", "error", "stale" or "skipped"
	 */
	public function process(int $screeningId, string $token, ?callable $onProgress = null): string {
		try {
			$screening = $this->mapper->findById($screeningId);
		} catch (DoesNotExistException) {
			return 'stale';
		}
		if ($screening->getMediaToken() !== $token) {
			return 'stale';
		}
		if ($screening->servedAs() === Screening::MODE_MP4) {
			return 'skipped';
		}

		if (!$this->mapper->updateMediaIfCurrent($screeningId, $token, [
			'media_status' => Screening::MEDIA_PROCESSING,
			'media_progress' => 0,
		])) {
			return 'stale';
		}

		$lastWrite = 0;
		$lastPercent = -1;
		$progress = function (float $fraction) use ($screeningId, $token, $onProgress, &$lastWrite, &$lastPercent): void {
			// Keep 100 for "done"; processing tops out at 99.
			$percent = min(99, (int)floor($fraction * 100));
			if ($onProgress !== null) {
				$onProgress($fraction);
			}
			if ($percent !== $lastPercent && time() - $lastWrite >= self::PROGRESS_INTERVAL_SECONDS) {
				$this->mapper->updateMediaIfCurrent($screeningId, $token, ['media_progress' => $percent]);
				$lastWrite = time();
				$lastPercent = $percent;
			}
		};

		try {
			$durationMs = $this->run($screening, $token, $progress);
		} catch (MediaException $e) {
			$this->storage->delete($screeningId, $token);
			$this->mapper->updateMediaIfCurrent($screeningId, $token, [
				'media_status' => Screening::MEDIA_ERROR,
				'media_error' => $e->getMessage(),
			]);
			return 'error';
		} catch (\Throwable $e) {
			$this->logger->error('Processing media for screening ' . $screeningId . ' failed', ['exception' => $e]);
			$this->storage->delete($screeningId, $token);
			$this->mapper->updateMediaIfCurrent($screeningId, $token, [
				'media_status' => Screening::MEDIA_ERROR,
				'media_error' => 'Processing failed unexpectedly. The server log has details.',
			]);
			return 'error';
		}

		$current = $this->mapper->updateMediaIfCurrent($screeningId, $token, [
			'media_status' => Screening::MEDIA_READY,
			'media_progress' => 100,
			'duration_ms' => $durationMs,
		]);
		if (!$current) {
			$this->storage->delete($screeningId, $token);
			return 'stale';
		}
		return 'ready';
	}

	/**
	 * @return int duration in milliseconds
	 * @throws MediaException
	 */
	private function run(Screening $screening, string $token, callable $progress): int {
		$file = $this->media->sourceFile($screening) ?? throw new MediaException('The source file no longer exists');
		$key = random_bytes(16);
		$folder = $this->storage->createFolder($screening->getId(), $token);
		$folder->newFile(MediaStorage::KEY_FILE, $key);

		if ($screening->servedAs() === Screening::MODE_HLS) {
			$userFolder = $this->rootFolder->getUserFolder($screening->getOwnerUid());
			$masterPath = $userFolder->getRelativePath($file->getPath()) ?? throw new MediaException('The playlist is outside your files');
			return $this->packager->package($masterPath, HlsPackager::userFolderReader($userFolder), $folder, $key, $progress);
		}

		$workDir = $this->tempManager->getTemporaryFolder();
		if ($workDir === false) {
			throw new MediaException('Could not create a temporary folder for transcoding');
		}
		try {
			$source = $file->getStorage()->getLocalFile($file->getInternalPath());
			if ($source === false) {
				throw new MediaException('Could not read the source video');
			}
			$mode = $screening->getDeliveryMode() === Screening::MODE_AUTO
				? $this->autoPlan($screening, $token, $source)
				: $this->modes->transcodeMode();
			// Encoding is the slow part (0-90%); encrypting the output is quick.
			$this->transcoder->transcode($source, $workDir, $mode, fn (float $f) => $progress($f * 0.9));
			return $this->packager->package(
				'/' . MediaStorage::MASTER_PLAYLIST,
				HlsPackager::localReader($workDir),
				$folder,
				$key,
				fn (float $f) => $progress(0.9 + $f * 0.1),
			);
		} finally {
			self::removeDirectory($workDir);
		}
	}

	/**
	 * Picks Auto's conversion for this file and records it, so the portal can
	 * show what is happening.
	 *
	 * @throws MediaException
	 */
	private function autoPlan(Screening $screening, string $token, string $source): string {
		$plan = Transcoder::plan($this->ffmpeg->probe($source));
		// The admin's "repackage only" setting (for weak servers) also limits Auto.
		if ($plan === Transcoder::MODE_FULL && $this->modes->transcodeMode() === Transcoder::MODE_REMUX) {
			throw new MediaException('This video needs a full conversion, but the server is set to repackage only (Administration settings → Screenings). Export it as H.264, or ask the admin to allow full conversion.');
		}
		$this->mapper->updateMediaIfCurrent($screening->getId(), $token, ['media_mode' => $plan]);
		return $plan;
	}

	private static function removeDirectory(string $dir): void {
		foreach (scandir($dir) ?: [] as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}
			$path = $dir . '/' . $name;
			is_dir($path) ? self::removeDirectory($path) : @unlink($path);
		}
		@rmdir($dir);
	}
}

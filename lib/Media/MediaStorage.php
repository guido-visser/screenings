<?php

declare(strict_types=1);

namespace OCA\Screenings\Media;

use OCA\Screenings\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Processed media (encrypted HLS playlists, segments and the key) lives in
 * the app's data folder, outside every user's Files:
 *
 *   appdata_<instance>/screenings/media/<screeningId>-<mediaToken>/
 *
 * The token changes on every processing run, so a stale job can never write
 * into the folder the viewer is served from.
 */
class MediaStorage {
	public const KEY_FILE = 'key.bin';
	public const MASTER_PLAYLIST = 'master.m3u8';
	/** Placeholder written as the key URI; the stream controller swaps in a signed URL. */
	public const KEY_URI_PLACEHOLDER = 'screenings-key';

	private IAppData $appData;

	public function __construct(IAppDataFactory $appDataFactory) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	public static function folderName(int $screeningId, string $token): string {
		return $screeningId . '-' . $token;
	}

	public function createFolder(int $screeningId, string $token): ISimpleFolder {
		$this->delete($screeningId, $token);
		return $this->mediaRoot()->newFolder(self::folderName($screeningId, $token));
	}

	/**
	 * @throws NotFoundException
	 */
	public function getFolder(int $screeningId, string $token): ISimpleFolder {
		return $this->mediaRoot()->getFolder(self::folderName($screeningId, $token));
	}

	public function delete(int $screeningId, ?string $token): void {
		if ($token === null) {
			return;
		}
		try {
			$this->getFolder($screeningId, $token)->delete();
		} catch (NotFoundException) {
			// Nothing stored for this run.
		}
	}

	private function mediaRoot(): ISimpleFolder {
		try {
			return $this->appData->getFolder('media');
		} catch (NotFoundException) {
			return $this->appData->newFolder('media');
		}
	}
}

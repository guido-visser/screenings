<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Media\Transcoder;
use OCP\IAppConfig;

/**
 * Which delivery modes this server offers. Server transcoding is opt-in
 * (admin settings) because it needs ffmpeg and CPU.
 */
class DeliveryModes {
	public const CONFIG_TRANSCODE_ENABLED = 'transcode_enabled';
	public const CONFIG_TRANSCODE_MODE = 'transcode_mode';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @return string[]
	 */
	public function available(): array {
		// Auto is always offered: without transcoding it still plays
		// browser-ready MP4s, and explains what other files need.
		$modes = [Screening::MODE_AUTO, Screening::MODE_MP4, Screening::MODE_HLS];
		if ($this->transcodeEnabled()) {
			$modes[] = Screening::MODE_TRANSCODE;
		}
		return $modes;
	}

	public function isAvailable(string $mode): bool {
		return in_array($mode, $this->available(), true);
	}

	public function transcodeEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::CONFIG_TRANSCODE_ENABLED, false);
	}

	/**
	 * Transcoder::MODE_FULL (re-encode to several qualities) or
	 * Transcoder::MODE_REMUX (repackage only, for low-power servers).
	 */
	public function transcodeMode(): string {
		$mode = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_TRANSCODE_MODE, Transcoder::MODE_FULL);
		return $mode === Transcoder::MODE_REMUX ? Transcoder::MODE_REMUX : Transcoder::MODE_FULL;
	}
}

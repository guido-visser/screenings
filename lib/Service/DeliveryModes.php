<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\Screening;
use OCP\IAppConfig;

/**
 * Which delivery modes this server offers. Server transcoding is opt-in
 * (admin setting, added in phase 3) because it needs ffmpeg and CPU.
 */
class DeliveryModes {
	public const CONFIG_TRANSCODE_ENABLED = 'transcode_enabled';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @return string[]
	 */
	public function available(): array {
		$modes = [Screening::MODE_MP4, Screening::MODE_HLS];
		if ($this->appConfig->getValueBool(Application::APP_ID, self::CONFIG_TRANSCODE_ENABLED, false)) {
			$modes[] = Screening::MODE_TRANSCODE;
		}
		return $modes;
	}

	public function isAvailable(string $mode): bool {
		return in_array($mode, $this->available(), true);
	}
}

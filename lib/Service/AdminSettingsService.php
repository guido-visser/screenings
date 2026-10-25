<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Media\Ffmpeg;
use OCA\Screenings\Media\Transcoder;
use OCP\IAppConfig;

class AdminSettingsService {
	public function __construct(
		private IAppConfig $appConfig,
		private DeliveryModes $modes,
		private Ffmpeg $ffmpeg,
	) {
	}

	public function get(): array {
		return [
			'transcodeEnabled' => $this->modes->transcodeEnabled(),
			'transcodeMode' => $this->modes->transcodeMode(),
			'ffmpegPath' => $this->appConfig->getValueString(Application::APP_ID, Ffmpeg::CONFIG_FFMPEG_PATH, ''),
			'ffprobePath' => $this->appConfig->getValueString(Application::APP_ID, Ffmpeg::CONFIG_FFPROBE_PATH, ''),
			'detected' => $this->ffmpeg->detect(),
		];
	}

	/**
	 * @throws ValidationException
	 */
	public function update(array $data): array {
		foreach (['ffmpegPath' => Ffmpeg::CONFIG_FFMPEG_PATH, 'ffprobePath' => Ffmpeg::CONFIG_FFPROBE_PATH] as $field => $key) {
			if (!array_key_exists($field, $data)) {
				continue;
			}
			$path = trim((string)$data[$field]);
			if ($path !== '' && (!str_starts_with($path, '/') || !is_file($path) || !is_executable($path))) {
				throw new ValidationException('Enter the absolute path of an executable, or leave it empty to detect it automatically', $field);
			}
			$this->appConfig->setValueString(Application::APP_ID, $key, $path);
		}

		if (array_key_exists('transcodeMode', $data)) {
			$mode = (string)$data['transcodeMode'];
			if (!in_array($mode, [Transcoder::MODE_FULL, Transcoder::MODE_REMUX], true)) {
				throw new ValidationException('Unknown transcoding mode', 'transcodeMode');
			}
			$this->appConfig->setValueString(Application::APP_ID, DeliveryModes::CONFIG_TRANSCODE_MODE, $mode);
		}

		if (array_key_exists('transcodeEnabled', $data)) {
			$enabled = (bool)$data['transcodeEnabled'];
			if ($enabled && !$this->ffmpeg->isAvailable()) {
				throw new ValidationException('Server transcoding needs ffmpeg and ffprobe, which were not found', 'transcodeEnabled');
			}
			$this->appConfig->setValueBool(Application::APP_ID, DeliveryModes::CONFIG_TRANSCODE_ENABLED, $enabled);
		}

		return $this->get();
	}
}

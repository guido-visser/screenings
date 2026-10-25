<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Media\Ffmpeg;
use OCA\Screenings\Media\Transcoder;
use OCA\Screenings\Tracking\GeoIpService;
use OCA\Screenings\Tracking\GeoIpUpdater;
use OCP\IAppConfig;

class AdminSettingsService {
	public function __construct(
		private IAppConfig $appConfig,
		private DeliveryModes $modes,
		private Ffmpeg $ffmpeg,
		private GeoIpService $geoIp,
		private GeoIpUpdater $geoIpUpdater,
	) {
	}

	public function get(): array {
		return [
			'transcodeEnabled' => $this->modes->transcodeEnabled(),
			'transcodeMode' => $this->modes->transcodeMode(),
			'ffmpegPath' => $this->appConfig->getValueString(Application::APP_ID, Ffmpeg::CONFIG_FFMPEG_PATH, ''),
			'ffprobePath' => $this->appConfig->getValueString(Application::APP_ID, Ffmpeg::CONFIG_FFPROBE_PATH, ''),
			'detected' => $this->ffmpeg->detect(),
			'geoip' => [
				'mode' => $this->geoIp->mode(),
				'path' => $this->appConfig->getValueString(Application::APP_ID, GeoIpService::CONFIG_PATH, ''),
				'accountId' => $this->appConfig->getValueString(Application::APP_ID, GeoIpService::CONFIG_ACCOUNT, ''),
				// The key itself never goes back to the browser.
				'hasLicenseKey' => $this->appConfig->getValueString(Application::APP_ID, GeoIpService::CONFIG_LICENSE, '') !== '',
				'status' => $this->geoIp->status(),
			],
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

		if (isset($data['geoip']) && is_array($data['geoip'])) {
			$this->updateGeoIp($data['geoip']);
		}

		return $this->get();
	}

	/**
	 * @throws ValidationException
	 */
	public function downloadGeoIp(): array {
		$this->geoIpUpdater->update();
		return $this->get();
	}

	/**
	 * @throws ValidationException
	 */
	private function updateGeoIp(array $geo): void {
		if (array_key_exists('path', $geo)) {
			$path = trim((string)$geo['path']);
			if ($path !== '' && (!str_starts_with($path, '/') || !str_ends_with($path, '.mmdb') || !is_readable($path))) {
				throw new ValidationException('Enter the absolute path of a readable .mmdb file', 'geoipPath');
			}
			$this->appConfig->setValueString(Application::APP_ID, GeoIpService::CONFIG_PATH, $path);
		}
		if (array_key_exists('accountId', $geo)) {
			$account = trim((string)$geo['accountId']);
			if ($account !== '' && !ctype_digit($account)) {
				throw new ValidationException('The MaxMind account ID is a number', 'geoipAccount');
			}
			$this->appConfig->setValueString(Application::APP_ID, GeoIpService::CONFIG_ACCOUNT, $account);
		}
		// Only replaced when a new key is typed; an empty field keeps the old one.
		if (isset($geo['licenseKey']) && trim((string)$geo['licenseKey']) !== '') {
			$this->appConfig->setValueString(Application::APP_ID, GeoIpService::CONFIG_LICENSE, trim((string)$geo['licenseKey']), false, true);
		}
		if (array_key_exists('mode', $geo)) {
			$mode = (string)$geo['mode'];
			if (!in_array($mode, [GeoIpService::MODE_OFF, GeoIpService::MODE_MAXMIND, GeoIpService::MODE_FILE], true)) {
				throw new ValidationException('Unknown location mode', 'geoipMode');
			}
			if ($mode === GeoIpService::MODE_FILE && $this->appConfig->getValueString(Application::APP_ID, GeoIpService::CONFIG_PATH, '') === '') {
				throw new ValidationException('Enter the path of the database file first', 'geoipPath');
			}
			$this->appConfig->setValueString(Application::APP_ID, GeoIpService::CONFIG_MODE, $mode);
		}
	}
}

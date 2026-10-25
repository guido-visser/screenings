<?php

declare(strict_types=1);

namespace OCA\Screenings\Tracking;

use MaxMind\Db\Reader;
use OCA\Screenings\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Optional IP → country/city lookup for the dashboard, using a MaxMind
 * GeoLite2/GeoIP2 City database on this server. No IP leaves the server.
 *
 * Modes (admin settings):
 *   off      no lookups
 *   maxmind  the app downloads GeoLite2-City weekly with the admin's free
 *            MaxMind account ID and license key
 *   file     an existing .mmdb file, e.g. kept up to date by geoipupdate
 */
class GeoIpService {
	public const MODE_OFF = 'off';
	public const MODE_MAXMIND = 'maxmind';
	public const MODE_FILE = 'file';

	public const CONFIG_MODE = 'geoip_mode';
	public const CONFIG_PATH = 'geoip_path';
	public const CONFIG_ACCOUNT = 'maxmind_account_id';
	public const CONFIG_LICENSE = 'maxmind_license_key';

	private ?Reader $reader = null;
	private bool $readerFailed = false;

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function mode(): string {
		$mode = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_MODE, self::MODE_OFF);
		return in_array($mode, [self::MODE_MAXMIND, self::MODE_FILE], true) ? $mode : self::MODE_OFF;
	}

	/**
	 * Where the database is (or will be) for the current mode.
	 */
	public function databasePath(): ?string {
		return match ($this->mode()) {
			self::MODE_FILE => $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_PATH, '') ?: null,
			self::MODE_MAXMIND => self::downloadPath($this->config),
			default => null,
		};
	}

	/**
	 * The app's own copy lives in the data directory (always a local disk,
	 * unlike app data on object storage), because the reader needs a file path.
	 */
	public static function downloadPath(IConfig $config): string {
		return rtrim($config->getSystemValueString('datadirectory'), '/') . '/screenings-geoip/GeoLite2-City.mmdb';
	}

	/**
	 * @return array{country: ?string, city: ?string}
	 */
	public function lookup(?string $ip): array {
		$none = ['country' => null, 'city' => null];
		// Private and reserved addresses (LAN, Docker, localhost) aren't in the database.
		if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return $none;
		}
		$reader = $this->reader();
		if ($reader === null) {
			return $none;
		}
		try {
			$record = $reader->get($ip);
		} catch (\Throwable $e) {
			$this->logger->debug('GeoIP lookup failed', ['exception' => $e]);
			return $none;
		}
		if (!is_array($record)) {
			return $none;
		}
		$country = $record['country']['iso_code'] ?? $record['registered_country']['iso_code'] ?? null;
		$names = $record['city']['names'] ?? [];
		$city = $names['en'] ?? (is_array($names) && $names !== [] ? reset($names) : null);
		return [
			'country' => is_string($country) ? substr($country, 0, 2) : null,
			'city' => is_string($city) ? mb_substr($city, 0, 128) : null,
		];
	}

	/**
	 * @return array{mode: string, path: ?string, available: bool, type: ?string, builtAt: ?int, error: ?string}
	 */
	public function status(): array {
		$path = $this->databasePath();
		$status = ['mode' => $this->mode(), 'path' => $path, 'available' => false, 'type' => null, 'builtAt' => null, 'error' => null];
		if ($path === null) {
			return $status;
		}
		if (!is_file($path)) {
			$status['error'] = $this->mode() === self::MODE_MAXMIND ? 'not_downloaded' : 'missing';
			return $status;
		}
		try {
			$reader = new Reader($path);
			$metadata = $reader->metadata();
			$reader->close();
		} catch (\Throwable) {
			$status['error'] = 'invalid';
			return $status;
		}
		if (!str_contains($metadata->databaseType, 'City') && !str_contains($metadata->databaseType, 'Country')) {
			$status['error'] = 'wrong_type';
		}
		return ['available' => $status['error'] === null, 'type' => $metadata->databaseType, 'builtAt' => $metadata->buildEpoch] + $status;
	}

	private function reader(): ?Reader {
		if ($this->reader !== null || $this->readerFailed) {
			return $this->reader;
		}
		$path = $this->databasePath();
		if ($path === null || !is_file($path)) {
			$this->readerFailed = true;
			return null;
		}
		try {
			$this->reader = new Reader($path);
		} catch (\Throwable $e) {
			$this->logger->warning('The GeoIP database could not be opened', ['exception' => $e]);
			$this->readerFailed = true;
		}
		return $this->reader;
	}
}

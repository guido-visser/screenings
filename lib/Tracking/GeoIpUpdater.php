<?php

declare(strict_types=1);

namespace OCA\Screenings\Tracking;

use MaxMind\Db\Reader;
use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Exception\ValidationException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;

/**
 * Downloads the free GeoLite2-City database from MaxMind with the admin's
 * account ID and license key, and installs it atomically.
 */
class GeoIpUpdater {
	private const URL = 'https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz';

	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private IConfig $config,
		private ITempManager $tempManager,
	) {
	}

	public function hasCredentials(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, GeoIpService::CONFIG_ACCOUNT, '') !== ''
			&& $this->appConfig->getValueString(Application::APP_ID, GeoIpService::CONFIG_LICENSE, '') !== '';
	}

	/**
	 * @throws ValidationException with a message for the admin
	 */
	public function update(): void {
		if (!$this->hasCredentials()) {
			throw new ValidationException('Enter your MaxMind account ID and license key first');
		}
		$workDir = $this->tempManager->getTemporaryFolder();
		if ($workDir === false) {
			throw new ValidationException('Could not create a temporary folder');
		}
		$archive = rtrim($workDir, '/') . '/GeoLite2-City.tar.gz';

		try {
			$this->clientService->newClient()->get(self::URL, [
				'auth' => [
					$this->appConfig->getValueString(Application::APP_ID, GeoIpService::CONFIG_ACCOUNT, ''),
					$this->appConfig->getValueString(Application::APP_ID, GeoIpService::CONFIG_LICENSE, ''),
				],
				'sink' => $archive,
				'timeout' => 300,
			]);
		} catch (\Throwable $e) {
			$status = method_exists($e, 'getResponse') && $e->getResponse() !== null ? $e->getResponse()->getStatusCode() : null;
			throw new ValidationException($status === 401
				? 'MaxMind rejected the account ID or license key'
				: 'Downloading the database from MaxMind failed');
		}

		try {
			$tar = new \PharData($archive);
			$mmdb = null;
			foreach (new \RecursiveIteratorIterator($tar) as $file) {
				if (str_ends_with($file->getFilename(), '.mmdb')) {
					$mmdb = $file->getPathname();
					break;
				}
			}
			if ($mmdb === null) {
				throw new ValidationException('The download did not contain a database');
			}

			$target = GeoIpService::downloadPath($this->config);
			if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true)) {
				throw new ValidationException('Could not create ' . dirname($target));
			}
			$staging = $target . '.new';
			if (!copy($mmdb, $staging)) {
				throw new ValidationException('Could not write the database');
			}
			// Make sure it opens before replacing the working copy.
			(new Reader($staging))->close();
			rename($staging, $target);
		} catch (ValidationException $e) {
			throw $e;
		} catch (\Throwable) {
			throw new ValidationException('The downloaded database could not be read');
		} finally {
			@unlink($archive);
		}
	}
}

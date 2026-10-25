<?php

declare(strict_types=1);

namespace OCA\Screenings\BackgroundJob;

use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Tracking\GeoIpService;
use OCA\Screenings\Tracking\GeoIpUpdater;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Refreshes the GeoLite2 database weekly when the admin chose the MaxMind
 * download (MaxMind publishes updates twice a week).
 */
class GeoIpUpdateJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private GeoIpService $geoIp,
		private GeoIpUpdater $updater,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(7 * 24 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if ($this->geoIp->mode() !== GeoIpService::MODE_MAXMIND || !$this->updater->hasCredentials()) {
			return;
		}
		try {
			$this->updater->update();
		} catch (ValidationException $e) {
			$this->logger->warning('Updating the GeoIP database failed: ' . $e->getMessage());
		}
	}
}

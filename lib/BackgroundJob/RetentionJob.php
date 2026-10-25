<?php

declare(strict_types=1);

namespace OCA\Screenings\BackgroundJob;

use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\InviteMapper;
use OCA\Screenings\Db\OtpMapper;
use OCA\Screenings\Db\ScreeningMapper;
use OCA\Screenings\Service\TrackingCleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily: deletes viewer sessions, events and watched ranges older than each
 * screening's retention period, and expired email codes. Invites themselves
 * stay, so the owner keeps the list of who was invited.
 */
class RetentionJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ScreeningMapper $screenings,
		private InviteMapper $invites,
		private OtpMapper $otps,
		private TrackingCleanupService $cleanup,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$now = $this->time->getTime();
		$this->otps->deleteExpired($now);
		foreach ($this->screenings->findRetentionDays() as $screeningId => $days) {
			$inviteIds = array_map(fn (Invite $i) => $i->getId(), $this->invites->findForScreening($screeningId));
			$deleted = $this->cleanup->deleteOlderThan($screeningId, $inviteIds, $now - max(1, $days) * 86400);
			if ($deleted > 0) {
				$this->logger->info("Retention: deleted $deleted viewer sessions of screening $screeningId");
			}
		}
	}
}

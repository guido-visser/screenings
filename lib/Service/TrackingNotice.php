<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Db\Screening;
use OCP\IL10N;

/**
 * The "your viewing is tracked" text shown on the viewer page and in
 * invitation emails, as the owner configured it per screening.
 */
class TrackingNotice {
	public const MAX_LENGTH = 500;

	public function __construct(
		private IL10N $l,
	) {
	}

	/**
	 * @return ?string null when the owner turned the notice off
	 */
	public function text(Screening $screening): ?string {
		if ($screening->getTrackingNoticeEnabled() === false) {
			return null;
		}
		return $screening->getTrackingNotice() ?? $this->l->t('Your viewing behaviour is tracked.');
	}
}

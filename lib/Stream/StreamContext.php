<?php

declare(strict_types=1);

namespace OCA\Screenings\Stream;

use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Db\ViewerSession;

class StreamContext {
	public function __construct(
		public readonly ViewerSession $session,
		public readonly Invite $invite,
		public readonly Screening $screening,
	) {
	}
}

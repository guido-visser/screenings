<?php

declare(strict_types=1);

namespace OCA\Screenings\Listener;

use OCA\Screenings\Db\ScreeningMapper;
use OCA\Screenings\Service\ScreeningService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Deletes a removed user's screenings with their invites, tracking data and
 * media copies. Without this, packaged HLS media in app data would stay
 * watchable through existing invite links.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private ScreeningMapper $mapper,
		private ScreeningService $screenings,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		foreach ($this->mapper->findAllForOwner($uid) as $screening) {
			$this->screenings->delete($screening->getId(), $uid);
		}
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Screenings\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Adds the "Create screening" file action to the Files app.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadFilesScriptsListener implements IEventListener {
	public function __construct(
		private IUserSession $userSession,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		// Public share pages fire this event too; screenings need an owner.
		if ($this->userSession->getUser() === null) {
			return;
		}
		Util::addInitScript(Application::APP_ID, Application::APP_ID . '-files');
	}
}

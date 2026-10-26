<?php

declare(strict_types=1);

namespace OCA\Screenings\Notification;

use OCA\Screenings\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * "Anna left 4 notes on Bloedtranen": one notification per viewer session,
 * replaced as more notes arrive (see FeedbackService::notifyOwner).
 */
class Notifier implements INotifier {
	public const SUBJECT_NOTES = 'notes';
	public const OBJECT_SESSION = 'viewer_session';

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Screenings');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== self::SUBJECT_NOTES) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();
		$count = (int)($p['count'] ?? 1);
		$notification->setParsedSubject($l->n(
			'%1$s left a note on %2$s',
			'%1$s left %3$d notes on %2$s',
			$count,
			[(string)($p['viewer'] ?? ''), (string)($p['title'] ?? ''), $count],
		));
		$notification->setLink($this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index') . '#/screenings/' . (int)($p['screeningId'] ?? 0) . '/notes');
		$notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}
}

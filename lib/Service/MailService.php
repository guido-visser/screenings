<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Exception\ValidationException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Invitation and one-time-code emails. They are sent from the server's
 * address in the owner's name, with replies going to the owner.
 */
class MailService {
	public const MAX_MESSAGE = 2000;

	public function __construct(
		private IMailer $mailer,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private TrackingNotice $trackingNotice,
		private LoggerInterface $logger,
	) {
	}

	public function publicLink(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.public.show', ['token' => $token]);
	}

	/**
	 * @throws ValidationException when the mail could not be sent
	 */
	public function sendInvitation(Screening $screening, Invite $invite, string $personalMessage = ''): void {
		$owner = $this->ownerName($screening);
		$link = $this->publicLink($invite->getToken());

		$template = $this->mailer->createEMailTemplate('screenings.Invitation', [
			'screening' => $screening->getTitle(),
			'owner' => $owner,
		]);
		$template->setSubject($this->l->t('%1$s invited you to a private screening of "%2$s"', [$owner, $screening->getTitle()]));
		$template->addHeader();
		$template->addHeading($this->l->t('A private screening for you'));
		$template->addBodyText($this->l->t('Hello %1$s, %2$s invited you to watch "%3$s".', [$invite->getName(), $owner, $screening->getTitle()]));

		$personalMessage = trim($personalMessage);
		if ($personalMessage !== '') {
			$template->addBodyText(htmlspecialchars($personalMessage), $personalMessage);
		}
		if ($screening->getDescription()) {
			$template->addBodyText(htmlspecialchars($screening->getDescription()), $screening->getDescription());
		}
		if ($screening->getPasswordHash() !== null) {
			$template->addBodyText($this->l->t('The screening is protected with a password, which %s will give you separately.', [$owner]));
		}
		if ($screening->getExpiresAt() !== null) {
			$template->addBodyText($this->l->t('The link works until %s.', [$this->l->l('datetime', $screening->getExpiresAt())]));
		}
		$template->addBodyButton($this->l->t('Watch the screening'), $link);
		$template->addBodyText($this->l->t('This link is personal; please do not forward it.'));
		// Same notice as on the screening page, so the mail never promises less tracking than there is.
		$notice = $this->trackingNotice->text($screening);
		if ($notice !== null) {
			$template->addBodyText($notice);
		}
		$template->addFooter();

		$this->send($screening, $invite->getEmail(), $invite->getName(), $template);
	}

	/**
	 * @throws ValidationException when the mail could not be sent
	 */
	public function sendCode(Screening $screening, string $name, string $email, string $code): void {
		$template = $this->mailer->createEMailTemplate('screenings.Code', ['screening' => $screening->getTitle()]);
		$template->setSubject($this->l->t('Your code for "%s": %s', [$screening->getTitle(), $code]));
		$template->addHeader();
		$template->addHeading($this->l->t('Your code: %s', [$code]));
		$template->addBodyText($this->l->t('Enter this code on the screening page to watch "%s". It expires in %d minutes.', [$screening->getTitle(), OtpService::CODE_MINUTES]));
		$template->addBodyText($this->l->t('If you did not ask for this, you can ignore this email.'));
		$template->addFooter();

		$this->send($screening, $email, $name, $template);
	}

	private function send(Screening $screening, string $email, string $name, $template): void {
		$message = $this->mailer->createMessage();
		$message->setFrom([Util::getDefaultEmailAddress('noreply') => $this->l->t('%s via Screenings', [$this->ownerName($screening)])]);
		$ownerEmail = $this->userManager->get($screening->getOwnerUid())?->getEMailAddress();
		if ($ownerEmail) {
			$message->setReplyTo([$ownerEmail => $this->ownerName($screening)]);
		}
		$message->setTo([$email => $name]);
		$message->useTemplate($template);

		try {
			$failed = $this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->error('Could not send screening email', ['exception' => $e]);
			throw new ValidationException($this->l->t('The email could not be sent. Check the server\'s email settings.'));
		}
		if ($failed !== []) {
			throw new ValidationException($this->l->t('The email could not be delivered to %s', [$email]));
		}
	}

	private function ownerName(Screening $screening): string {
		return $this->userManager->getDisplayName($screening->getOwnerUid()) ?? $screening->getOwnerUid();
	}
}

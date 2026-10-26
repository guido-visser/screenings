<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Mail\MailTemplateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Invitation and one-time-code emails. They are sent from the server's
 * address in the owner's name, with replies going to the owner. The
 * invitation uses the owner's own design (MailTemplateService); the code
 * email keeps Nextcloud's standard layout.
 */
class MailService {
	public const MAX_MESSAGE = 2000;

	public function __construct(
		private IMailer $mailer,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private TrackingNotice $trackingNotice,
		private MailTemplateService $templates,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function publicLink(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.public.show', ['token' => $token]);
	}

	/**
	 * Sends the invitation in the screening's email design (see MailTemplateService).
	 *
	 * @throws ValidationException when the mail could not be sent
	 */
	public function sendInvitation(Screening $screening, Invite $invite, string $personalMessage = ''): void {
		$design = $this->templates->resolve($screening);
		$values = $this->invitationValues($screening, $invite->getName(), $invite->getEmail(), $this->publicLink($invite->getToken()), $personalMessage);
		$mail = MailTemplateService::render($design['subject'], $design['html'], $values);
		$this->sendRendered($screening, $invite->getEmail(), $invite->getName(), $mail);
	}

	/**
	 * A design filled with example viewer data (and the screening's own
	 * details, if given), for the editor's preview.
	 *
	 * @return array{subject: string, html: string, text: string}
	 * @throws ValidationException
	 */
	public function preview(string $uid, ?Screening $screening, string $subject, string $html): array {
		return MailTemplateService::render($subject, $html, $this->sampleValues($uid, $screening));
	}

	/**
	 * Sends the preview to the owner.
	 *
	 * @return string the address it went to
	 * @throws ValidationException
	 */
	public function sendTest(string $uid, ?Screening $screening, string $subject, string $html): string {
		$user = $this->userManager->get($uid);
		$email = $user?->getEMailAddress();
		if (!$email) {
			throw new ValidationException($this->l->t('Set an email address in your personal settings to receive test emails.'));
		}
		$mail = $this->preview($uid, $screening, $subject, $html);
		$mail['subject'] = '[Test] ' . $mail['subject'];
		$this->sendRendered($screening ?? $this->placeholderScreening($uid), $email, $user->getDisplayName(), $mail);
		return $email;
	}

	/**
	 * @return array<string, string|bool|null>
	 */
	private function invitationValues(Screening $screening, string $name, string $email, string $link, string $message): array {
		$durationMs = $screening->getDurationMs();
		return [
			'title' => $screening->getTitle(),
			'description' => (string)$screening->getDescription(),
			'duration' => $durationMs ? max(1, (int)round($durationMs / 60000)) . ' min' : '',
			'owner' => $this->ownerName($screening),
			'owner_email' => (string)$this->userManager->get($screening->getOwnerUid())?->getEMailAddress(),
			'name' => $name,
			'email' => $email,
			'link' => $link,
			'message' => trim($message),
			'expires' => $screening->getExpiresAt() !== null ? (string)$this->l->l('datetime', $screening->getExpiresAt()) : '',
			'password' => $screening->getPasswordHash() !== null,
			'tracking_notice' => (string)$this->trackingNotice->text($screening),
		];
	}

	/**
	 * @return array<string, string|bool|null>
	 */
	private function sampleValues(string $uid, ?Screening $screening): array {
		$values = $this->invitationValues(
			$screening ?? $this->placeholderScreening($uid),
			'Alex Example',
			'alex@example.com',
			$this->publicLink('preview0000000000000000000000000'),
			'This is where the personal message appears that you can write when you send an invitation.',
		);
		if ($screening === null) {
			// The default design: show every optional part once.
			$values['expires'] = (string)$this->l->l('datetime', $this->time->getTime() + 14 * 86400);
			$values['duration'] = '26 min';
		}
		return $values;
	}

	/** An unsaved screening with example details, for previews of the default design. */
	private function placeholderScreening(string $uid): Screening {
		$screening = new Screening();
		$screening->setOwnerUid($uid);
		$screening->setTitle('Your film');
		$screening->setDescription('The description of the screening appears here.');
		$screening->setTrackingNoticeEnabled(true);
		return $screening;
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
		$message = $this->newMessage($screening, $email, $name);
		$message->useTemplate($template);
		$this->deliver($message, $email);
	}

	/**
	 * @param array{subject: string, html: string, text: string} $mail
	 */
	private function sendRendered(Screening $screening, string $email, string $name, array $mail): void {
		$message = $this->newMessage($screening, $email, $name);
		$message->setSubject($mail['subject']);
		$message->setHtmlBody($mail['html']);
		$message->setPlainBody($mail['text']);
		$this->deliver($message, $email);
	}

	private function newMessage(Screening $screening, string $email, string $name): IMessage {
		$message = $this->mailer->createMessage();
		$message->setFrom([Util::getDefaultEmailAddress('noreply') => $this->l->t('%s via Screenings', [$this->ownerName($screening)])]);
		$ownerEmail = $this->userManager->get($screening->getOwnerUid())?->getEMailAddress();
		if ($ownerEmail) {
			$message->setReplyTo([$ownerEmail => $this->ownerName($screening)]);
		}
		$message->setTo([$email => $name]);
		return $message;
	}

	private function deliver(IMessage $message, string $email): void {
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

<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\InviteMapper;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;

class InviteService {
	/**
	 * Invitation mails per owner and hour. Plenty for a real guest list, but it
	 * stops a hijacked account from turning the server into a spam relay.
	 */
	public const MAIL_LIMIT = 100;
	public const MAIL_PERIOD = 3600;

	public function __construct(
		private InviteMapper $mapper,
		private TrackingCleanupService $cleanup,
		private TokenGenerator $tokens,
		private MailService $mail,
		private IMailer $mailer,
		private ITimeFactory $time,
		private IDBConnection $db,
		private ILimiter $limiter,
		private IUserSession $userSession,
	) {
	}

	/**
	 * @return Invite[]
	 */
	public function listForScreening(int $screeningId): array {
		return $this->mapper->findForScreening($screeningId);
	}

	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Invite {
		try {
			return $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Invite not found');
		}
	}

	/**
	 * @throws ValidationException
	 */
	public function create(int $screeningId, string $name, string $email, string $source = Invite::SOURCE_INVITED): Invite {
		$name = trim($name);
		$email = trim($email);
		if ($name === '') {
			throw new ValidationException('A name is required', 'name');
		}
		if (mb_strlen($name) > 255) {
			throw new ValidationException('The name is too long', 'name');
		}
		if (!$this->mailer->validateMailAddress($email)) {
			throw new ValidationException('Enter a valid email address', 'email');
		}
		try {
			$this->mapper->findByEmail($screeningId, $email);
			throw new ValidationException('This email address is already invited to this screening', 'email');
		} catch (DoesNotExistException) {
		}

		$invite = new Invite();
		$invite->setScreeningId($screeningId);
		$invite->setToken($this->tokens->linkToken());
		$invite->setName($name);
		$invite->setEmail($email);
		$invite->setSource($source);
		$invite->setCreatedAt($this->time->getTime());
		return $this->mapper->insert($invite);
	}

	/**
	 * Emails the invite its personal link and records when.
	 *
	 * @throws ValidationException
	 */
	public function sendInvitation(Screening $screening, Invite $invite, string $message = ''): Invite {
		if (mb_strlen($message) > MailService::MAX_MESSAGE) {
			throw new ValidationException('The message is too long', 'message');
		}
		if ($invite->isRevoked()) {
			throw new ValidationException('Restore access before sending an invitation');
		}
		$user = $this->userSession->getUser();
		if ($user !== null) {
			try {
				$this->limiter->registerUserRequest('screenings_invite_mail', self::MAIL_LIMIT, self::MAIL_PERIOD, $user);
			} catch (IRateLimitExceededException) {
				throw new ValidationException('Too many invitations sent in the last hour. Try again later.');
			}
		}
		$this->mail->sendInvitation($screening, $invite, $message);
		$invite->setInvitedAt($this->time->getTime());
		return $this->mapper->update($invite);
	}

	public function markEmailVerified(Invite $invite): Invite {
		if ($invite->getEmailVerifiedAt() === null) {
			$invite->setEmailVerifiedAt($this->time->getTime());
			$invite = $this->mapper->update($invite);
		}
		return $invite;
	}

	public function setRevoked(Invite $invite, bool $revoked): Invite {
		$invite->setRevokedAt($revoked ? $this->time->getTime() : null);
		return $this->mapper->update($invite);
	}

	/**
	 * Turns notes and closing questions on or off; null leaves a switch as it is.
	 *
	 * @throws ValidationException
	 */
	public function setFeedback(Invite $invite, ?bool $allowNotes, ?bool $askQuestions): Invite {
		if ($invite->getSource() === Invite::SOURCE_OPEN_LINK && ($allowNotes || $askQuestions)) {
			throw new ValidationException("Visitors from the open link can't leave feedback");
		}
		if ($allowNotes !== null) {
			$invite->setAllowNotes($allowNotes);
		}
		if ($askQuestions !== null) {
			$invite->setAskQuestions($askQuestions);
		}
		return $this->mapper->update($invite);
	}

	/**
	 * The same switches for every personal invite of a screening (open-link
	 * visitors are skipped).
	 *
	 * @return Invite[] the screening's invites afterwards
	 */
	public function setFeedbackForAll(int $screeningId, ?bool $allowNotes, ?bool $askQuestions): array {
		$invites = $this->mapper->findForScreening($screeningId);
		foreach ($invites as $invite) {
			if ($invite->getSource() !== Invite::SOURCE_OPEN_LINK) {
				$this->setFeedback($invite, $allowNotes, $askQuestions);
			}
		}
		return $invites;
	}

	/**
	 * Deletes the invite with all of its tracking data.
	 */
	public function delete(Invite $invite): void {
		$this->db->beginTransaction();
		try {
			$this->cleanup->deleteForInvites([$invite->getId()]);
			$this->mapper->delete($invite);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}

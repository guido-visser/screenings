<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\InviteMapper;
use OCA\Screenings\Db\Otp;
use OCA\Screenings\Db\OtpMapper;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Security\IHasher;

/**
 * The open link: visitors give their name and email, receive a six-digit
 * code, and get their own invite once they enter it. That way every viewer
 * has a confirmed email address, even without an invitation.
 */
class OtpService {
	public const CODE_MINUTES = 10;
	public const MAX_ATTEMPTS = 5;
	/** Codes per email address and screening within RATE_WINDOW. */
	public const MAX_CODES = 3;
	private const RATE_WINDOW = 15 * 60;

	public function __construct(
		private OtpMapper $otps,
		private InviteMapper $inviteMapper,
		private InviteService $invites,
		private MailService $mail,
		private IHasher $hasher,
		private IMailer $mailer,
		private ITimeFactory $time,
		private IL10N $l,
	) {
	}

	/**
	 * @throws ValidationException
	 */
	public function request(Screening $screening, string $name, string $email): void {
		$name = trim($name);
		$email = self::normalizeEmail($email);
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException($this->l->t('Enter your name'), 'name');
		}
		if (!$this->mailer->validateMailAddress($email)) {
			throw new ValidationException($this->l->t('Enter a valid email address'), 'email');
		}

		$now = $this->time->getTime();
		if ($this->otps->countSince($screening->getId(), $email, $now - self::RATE_WINDOW) >= self::MAX_CODES) {
			throw new ValidationException($this->l->t('Too many codes requested. Try again in a few minutes.'), 'email');
		}

		$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

		// Only the newest code is valid. Older rows are kept (with their
		// attempts spent) so the rate limit above still counts them.
		try {
			$previous = $this->otps->findLatest($screening->getId(), $email);
			$previous->setAttempts(self::MAX_ATTEMPTS);
			$this->otps->update($previous);
		} catch (DoesNotExistException) {
		}

		$otp = new Otp();
		$otp->setScreeningId($screening->getId());
		$otp->setEmail($email);
		$otp->setName($name);
		$otp->setCodeHash($this->hasher->hash($code));
		$otp->setAttempts(0);
		$otp->setExpiresAt($now + self::CODE_MINUTES * 60);
		$otp->setCreatedAt($now);
		$this->otps->insert($otp);

		$this->mail->sendCode($screening, $name, $email, $code);
	}

	/**
	 * Checks the code and returns the visitor's invite: an existing one for
	 * this email, or a new "open link" invite.
	 *
	 * @throws ValidationException
	 */
	public function verify(Screening $screening, string $email, string $code): Invite {
		$email = self::normalizeEmail($email);
		$code = preg_replace('/\s+/', '', $code);
		$invalid = new ValidationException($this->l->t('This code is not valid. Check it, or ask for a new one.'), 'code');

		try {
			$otp = $this->otps->findLatest($screening->getId(), $email);
		} catch (DoesNotExistException) {
			throw $invalid;
		}
		if ($otp->getExpiresAt() < $this->time->getTime() || $otp->getAttempts() >= self::MAX_ATTEMPTS) {
			throw $invalid;
		}
		$otp->setAttempts($otp->getAttempts() + 1);
		$this->otps->update($otp);
		if (!preg_match('/^\d{6}$/', $code) || !$this->hasher->verify($code, $otp->getCodeHash())) {
			throw $invalid;
		}
		$this->otps->deleteFor($screening->getId(), $email);

		try {
			$invite = $this->inviteMapper->findByEmail($screening->getId(), $email);
		} catch (DoesNotExistException) {
			$invite = $this->invites->create($screening->getId(), $otp->getName(), $email, Invite::SOURCE_OPEN_LINK);
		}
		return $this->invites->markEmailVerified($invite);
	}

	public static function normalizeEmail(string $email): string {
		return mb_strtolower(trim($email));
	}
}

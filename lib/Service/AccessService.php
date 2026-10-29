<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\InviteMapper;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Db\ScreeningMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Security\IHasher;

/**
 * Decides what a public link shows: which screening and invite it belongs
 * to, whether it may be watched, and whether the password was entered.
 */
class AccessService {
	public const DENIED_UNAVAILABLE = 'unavailable';
	public const DENIED_REVOKED = 'revoked';
	public const DENIED_EXPIRED = 'expired';

	/** Which kind of link a token is. */
	public const LINK_INVITE = 'invite';
	public const LINK_OPEN = 'open';
	public const LINK_PUBLIC = 'public';

	private const ACCESS_COOKIE_PREFIX = 'scr_access_';
	private const ACCESS_DAYS = 30;

	public function __construct(
		private InviteMapper $invites,
		private ScreeningMapper $screenings,
		private IHasher $hasher,
		private IConfig $config,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return ?array{invite: ?Invite, screening: Screening, link: string} null for unknown tokens.
	 *                                                                      invite is null for an open or public link.
	 */
	public function resolve(string $token): ?array {
		try {
			$invite = $this->invites->findByToken($token);
			// A public visitor's own token is never handed out; only the public link counts.
			return $invite->isPublicVisitor()
				? null
				: ['invite' => $invite, 'screening' => $this->screenings->findById($invite->getScreeningId()), 'link' => self::LINK_INVITE];
		} catch (DoesNotExistException) {
		}
		try {
			$screening = $this->screenings->findByOpenLinkToken($token);
			return $screening->getOpenLinkEnabled() ? ['invite' => null, 'screening' => $screening, 'link' => self::LINK_OPEN] : null;
		} catch (DoesNotExistException) {
		}
		try {
			$screening = $this->screenings->findByPublicLinkToken($token);
			return $screening->getPublicLinkEnabled() ? ['invite' => null, 'screening' => $screening, 'link' => self::LINK_PUBLIC] : null;
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Why this invite may not watch right now, or null if it may.
	 */
	public function denial(?Invite $invite, Screening $screening): ?string {
		if ($screening->isExpired($this->time->getTime())) {
			return self::DENIED_EXPIRED;
		}
		if ($invite !== null && self::isRevoked($invite, $screening)) {
			return self::DENIED_REVOKED;
		}
		return null;
	}

	/**
	 * Revoked by the owner, or a public visitor while the public link is off.
	 */
	public static function isRevoked(Invite $invite, Screening $screening): bool {
		return $invite->isRevoked() || ($invite->isPublicVisitor() && !$screening->getPublicLinkEnabled());
	}

	public function needsPassword(Invite $invite, Screening $screening, IRequest $request): bool {
		$hash = $screening->getPasswordHash();
		if ($hash === null) {
			return false;
		}
		return !AccessToken::verify(
			$this->secret(),
			$request->getCookie(self::ACCESS_COOKIE_PREFIX . $invite->getId()),
			$invite->getId(),
			$hash,
			$this->time->getTime(),
		);
	}

	/**
	 * Checks the password and, if it matches, remembers it in a cookie.
	 */
	public function unlock(Invite $invite, Screening $screening, string $password, Response $response): bool {
		$hash = $screening->getPasswordHash();
		if ($hash === null) {
			return true;
		}
		if (!$this->hasher->verify($password, $hash)) {
			return false;
		}
		$expiresAt = $this->time->getTime() + self::ACCESS_DAYS * 86400;
		// Lax, not Strict: the cookie must also arrive when the viewer follows
		// the link from their mail program (a cross-site navigation).
		$response->addCookie(
			self::ACCESS_COOKIE_PREFIX . $invite->getId(),
			AccessToken::create($this->secret(), $invite->getId(), $hash, $expiresAt),
			(new \DateTime())->setTimestamp($expiresAt),
			'Lax',
		);
		return true;
	}

	private function secret(): string {
		return $this->config->getSystemValueString('secret');
	}
}

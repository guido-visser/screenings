<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\ViewerSession;
use OCA\Screenings\Db\ViewerSessionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\Security\ISecureRandom;

/**
 * Viewer sessions: one per page load of a screening that passed all access
 * checks. The browser holds "<id>.<secret>" in an HttpOnly, SameSite=Strict
 * cookie; the database only stores a hash of the secret.
 */
class SessionService {
	private const COOKIE_PREFIX = 'scr_sess_';

	public function __construct(
		private ViewerSessionMapper $mapper,
		private ISecureRandom $random,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Starts a session and ends any other open session of the same invite, so
	 * one invite can only watch in one place at a time.
	 */
	public function start(Invite $invite, IRequest $request, Response $response): ViewerSession {
		$now = $this->time->getTime();
		$this->mapper->endOpenForInvite($invite->getId(), $now);

		$secret = $this->random->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
		$session = new ViewerSession();
		$session->setInviteId($invite->getId());
		$session->setSecretHash(hash('sha256', $secret));
		$session->setStartedAt($now);
		$session->setIp($request->getRemoteAddress());
		$session->setUserAgent(mb_substr($request->getHeader('User-Agent'), 0, 512));
		$session->setPlaying(false);
		$session = $this->mapper->insert($session);

		// No expiry date: a browser-session cookie.
		$response->addCookie(self::cookieName($invite), $session->getId() . '.' . $secret, null, 'Strict');
		return $session;
	}

	/**
	 * The open session the request's cookie points at, if any.
	 */
	public function fromRequest(Invite $invite, IRequest $request): ?ViewerSession {
		$session = $this->fromRequestIncludingEnded($invite, $request);
		return $session !== null && $session->getEndedAt() === null ? $session : null;
	}

	/**
	 * Like fromRequest(), but also returns a closed session, so callers can
	 * tell "replaced by a newer session" from "no valid cookie".
	 */
	public function fromRequestIncludingEnded(Invite $invite, IRequest $request): ?ViewerSession {
		$value = $request->getCookie(self::cookieName($invite));
		if (!is_string($value) || !preg_match('/^(\d+)\.([A-Za-z0-9]{40})$/', $value, $m)) {
			return null;
		}
		try {
			$session = $this->mapper->find((int)$m[1]);
		} catch (DoesNotExistException) {
			return null;
		}
		if ($session->getInviteId() !== $invite->getId() || !hash_equals($session->getSecretHash(), hash('sha256', $m[2]))) {
			return null;
		}
		return $session;
	}

	private static function cookieName(Invite $invite): string {
		return self::COOKIE_PREFIX . $invite->getId();
	}
}

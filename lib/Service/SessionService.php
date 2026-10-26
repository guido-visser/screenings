<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\ViewerSession;
use OCA\Screenings\Db\ViewerSessionMapper;
use OCA\Screenings\Tracking\GeoIpService;
use OCA\Screenings\Tracking\UserAgentParser;
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
	/** Positions before this aren't offered for resuming. */
	private const RESUME_MIN_S = 10.0;
	/** Within this much of the end, the film counts as finished. */
	private const RESUME_END_MARGIN_S = 60.0;

	public function __construct(
		private ViewerSessionMapper $mapper,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private GeoIpService $geoIp,
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
		$userAgent = $request->getHeader('User-Agent');
		$device = UserAgentParser::parse($userAgent);
		$location = $this->geoIp->lookup($request->getRemoteAddress());
		$session->setIp($request->getRemoteAddress());
		$session->setUserAgent(mb_substr($userAgent, 0, 512));
		$session->setBrowser($device['browser']);
		$session->setOs($device['os']);
		$session->setDeviceType($device['deviceType']);
		$session->setCountry($location['country']);
		$session->setCity($location['city']);
		$session->setPlaying(false);
		$session = $this->mapper->insert($session);

		// No expiry date: a browser-session cookie.
		$response->addCookie(self::cookieName($invite), $session->getId() . '.' . $secret, null, 'Strict');
		return $session;
	}

	/**
	 * Where the invite stopped watching last time, or null when there is
	 * nothing worth resuming: no earlier session past the first seconds, or
	 * one that reached the closing minute (credits) of the film.
	 * Call before start(), which doesn't touch earlier sessions' positions.
	 */
	public function resumePosition(Invite $invite, float $durationS): ?float {
		$last = $this->mapper->findLastWatched($invite->getId(), self::RESUME_MIN_S);
		$position = $last?->getLastPositionS();
		if ($position === null || ($durationS > 0 && $position >= $durationS - self::RESUME_END_MARGIN_S)) {
			return null;
		}
		return round($position, 1);
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

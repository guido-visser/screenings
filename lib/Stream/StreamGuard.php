<?php

declare(strict_types=1);

namespace OCA\Screenings\Stream;

use OCA\Screenings\Db\InviteMapper;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Db\ScreeningMapper;
use OCA\Screenings\Db\ViewerSessionMapper;
use OCA\Screenings\Service\SessionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Authorises every media request. All of these must hold:
 *
 * 1. the HttpOnly session cookie belongs to this (still open) session,
 * 2. the invite isn't revoked and the screening hasn't expired,
 * 3. for media: the page sent a heartbeat recently,
 * 4. for media: a valid playback token (HLS) or media signature (MP4, VTT).
 *
 * Revocation and expiry are therefore enforced on the very next request.
 */
class StreamGuard {
	public const TOKEN_HEADER = 'X-Screening-Token';
	/** Media requests need a heartbeat at most this old (the player sends one every 5 s). */
	public const HEARTBEAT_GRACE = 30;

	public const AUTH_COOKIE = 'cookie';
	public const AUTH_TOKEN = 'token';
	public const AUTH_SIGNATURE = 'signature';

	public function __construct(
		private ViewerSessionMapper $sessions,
		private InviteMapper $invites,
		private ScreeningMapper $screenings,
		private SessionService $sessionService,
		private IConfig $config,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param string $auth one of the AUTH_* constants
	 * @param ?string $resource for AUTH_SIGNATURE: what the signature covers
	 * @throws StreamDenied
	 */
	public function authorize(int $sessionId, IRequest $request, string $auth, ?string $resource = null): StreamContext {
		try {
			$session = $this->sessions->find($sessionId);
			$invite = $this->invites->find($session->getInviteId());
			$screening = $this->screenings->findById($invite->getScreeningId());
		} catch (DoesNotExistException) {
			throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
		}

		$fromCookie = $this->sessionService->fromRequestIncludingEnded($invite, $request);
		if ($fromCookie === null) {
			throw new StreamDenied(StreamDenied::TOKEN);
		}
		// The same invite opened the screening again: in another tab the
		// browser's cookie now points at the newer session; on another device
		// this session was ended.
		if ($fromCookie->getId() !== $session->getId() || $session->getEndedAt() !== null) {
			throw new StreamDenied(StreamDenied::REPLACED);
		}
		if ($invite->isRevoked()) {
			throw new StreamDenied(StreamDenied::REVOKED);
		}
		$now = $this->time->getTime();
		if ($screening->isExpired($now)) {
			throw new StreamDenied(StreamDenied::EXPIRED);
		}
		if ($screening->getMediaStatus() !== Screening::MEDIA_READY) {
			throw new StreamDenied(StreamDenied::NOT_READY);
		}

		if ($auth === self::AUTH_COOKIE) {
			return new StreamContext($session, $invite, $screening);
		}

		$lastSign = $session->getLastHeartbeatAt() ?? $session->getStartedAt();
		if ($now - $lastSign > self::HEARTBEAT_GRACE) {
			throw new StreamDenied(StreamDenied::STALE);
		}

		$userAgent = $request->getHeader('User-Agent');
		if ($auth === self::AUTH_TOKEN) {
			$token = $request->getHeader(self::TOKEN_HEADER) ?: null;
			if (!PlaybackToken::verify($this->secret(), $token, $session->getId(), $request->getRemoteAddress(), $userAgent, $now)) {
				throw new StreamDenied(StreamDenied::TOKEN);
			}
		} else {
			$signature = $request->getParam('sig');
			if ($resource === null || !PlaybackToken::verifyMediaSignature($this->secret(), is_string($signature) ? $signature : null, $session->getId(), $userAgent, $resource)) {
				throw new StreamDenied(StreamDenied::TOKEN);
			}
		}

		return new StreamContext($session, $invite, $screening);
	}

	/**
	 * For tracking events: the browser holds a valid cookie for this invite.
	 * Unlike media requests this also accepts ended, replaced, revoked or
	 * expired sessions, so their last events ("page_leave", "playback_stopped")
	 * are still recorded.
	 *
	 * @throws StreamDenied
	 */
	public function authorizeTracking(int $sessionId, IRequest $request): StreamContext {
		try {
			$session = $this->sessions->find($sessionId);
			$invite = $this->invites->find($session->getInviteId());
			$screening = $this->screenings->findById($invite->getScreeningId());
		} catch (DoesNotExistException) {
			throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
		}
		if ($this->sessionService->fromRequestIncludingEnded($invite, $request) === null) {
			throw new StreamDenied(StreamDenied::TOKEN);
		}
		return new StreamContext($session, $invite, $screening);
	}

	public function playbackToken(int $sessionId, IRequest $request): array {
		$expiresAt = $this->time->getTime() + PlaybackToken::TTL;
		return [
			'token' => PlaybackToken::create($this->secret(), $sessionId, $request->getRemoteAddress(), $request->getHeader('User-Agent'), $expiresAt),
			'ttl' => PlaybackToken::TTL,
		];
	}

	public function mediaSignature(int $sessionId, IRequest $request, string $resource): string {
		return PlaybackToken::mediaSignature($this->secret(), $sessionId, $request->getHeader('User-Agent'), $resource);
	}

	private function secret(): string {
		return $this->config->getSystemValueString('secret');
	}
}

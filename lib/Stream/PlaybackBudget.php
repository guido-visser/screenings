<?php

declare(strict_types=1);

namespace OCA\Screenings\Stream;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Limits how fast a session can pull media, so a scripted client can't
 * download the film much faster than someone watching it:
 *
 * - Playhead window: media more than AHEAD_SECONDS past the last position
 *   the player reported is refused. Seeking is fine; the player reports the
 *   seek target in a heartbeat and retries.
 * - Budget: a session may pull at most BURST_SECONDS of media plus SPEED
 *   seconds per second it has existed (re-fetches and quality switches
 *   included).
 *
 * The budget lives in the distributed cache (Redis). Without one it is not
 * enforced; the playhead window still is.
 */
class PlaybackBudget {
	public const AHEAD_SECONDS = 90;
	public const BURST_SECONDS = 180;
	public const SPEED = 3.0;

	private ICache $cache;

	public function __construct(ICacheFactory $cacheFactory) {
		$this->cache = $cacheFactory->createDistributed('screenings-budget');
	}

	public static function withinWindow(float $mediaStartS, ?float $lastPositionS): bool {
		return $mediaStartS <= max(0.0, $lastPositionS ?? 0.0) + self::AHEAD_SECONDS;
	}

	public static function allowedSeconds(int $sessionAgeSeconds): float {
		return self::BURST_SECONDS + max(0, $sessionAgeSeconds) * self::SPEED;
	}

	/**
	 * Records $mediaSeconds of media for the session, or refuses it.
	 *
	 * @throws StreamDenied
	 */
	public function consume(int $sessionId, int $sessionAgeSeconds, float $mediaSeconds): void {
		$key = 'session-' . $sessionId;
		$used = (float)($this->cache->get($key) ?? 0.0);
		if ($used + $mediaSeconds > self::allowedSeconds($sessionAgeSeconds)) {
			throw new StreamDenied(StreamDenied::AHEAD, 429);
		}
		$this->cache->set($key, $used + $mediaSeconds, 86400);
	}
}

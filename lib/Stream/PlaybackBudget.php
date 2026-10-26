<?php

declare(strict_types=1);

namespace OCA\Screenings\Stream;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Limits how fast an invite can pull media, so a scripted client can't
 * download the film much faster than someone watching it:
 *
 * - Playhead window: media more than AHEAD_SECONDS past the last position
 *   the player reported is refused. Seeking is fine; the player reports the
 *   seek target in a heartbeat and retries.
 * - Budget: a bucket of BURST_SECONDS of media that refills at SPEED seconds
 *   per second (re-fetches and quality switches included). It belongs to the
 *   invite, not the session: reloading the page starts a new session but
 *   not a new budget. And it never holds more than BURST_SECONDS, so a
 *   session paused for an hour doesn't save up an hour of fast downloading.
 *
 * The budget lives in the distributed cache (Redis). Without one it is not
 * enforced; the playhead window still is. Reads and writes aren't atomic, so
 * parallel requests can overspend by a request or two.
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

	/**
	 * What is left in a bucket that held $left seconds $elapsed seconds ago.
	 */
	public static function refilled(float $left, int $elapsed): float {
		return min((float)self::BURST_SECONDS, $left + max(0, $elapsed) * self::SPEED);
	}

	/**
	 * Takes $mediaSeconds of media from the invite's bucket, or refuses it.
	 *
	 * @throws StreamDenied
	 */
	public function consume(int $inviteId, int $now, float $mediaSeconds): void {
		$key = 'invite-' . $inviteId;
		$state = $this->cache->get($key);
		$left = is_array($state) && isset($state[0], $state[1])
			? self::refilled((float)$state[0], $now - (int)$state[1])
			: (float)self::BURST_SECONDS;
		if ($mediaSeconds > $left) {
			throw new StreamDenied(StreamDenied::AHEAD, 429);
		}
		// Once it would be full again the entry can go.
		$this->cache->set($key, [$left - $mediaSeconds, $now], (int)ceil(self::BURST_SECONDS / self::SPEED) + 60);
	}
}

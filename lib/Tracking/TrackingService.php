<?php

declare(strict_types=1);

namespace OCA\Screenings\Tracking;

use OCA\Screenings\Db\ViewerSession;
use OCA\Screenings\Db\ViewerSessionMapper;
use OCA\Screenings\Service\EventLogger;
use OCA\Screenings\Stream\StreamContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;

/**
 * Stores what the player reports: discrete events (play, pause, seek,
 * subtitle change, …) and the intervals that were actually played.
 *
 * Client input is untrusted: event types are whitelisted, payloads and
 * batches are size-limited, and played time can't exceed the wall-clock
 * time that passed.
 */
class TrackingService {
	/** Event type => the payload keys kept for it. */
	public const EVENT_TYPES = [
		'player_ready' => ['screen', 'mode'],
		'play' => [],
		'pause' => [],
		'seeked' => ['from', 'to'],
		'ended' => [],
		'volume_change' => ['volume', 'muted'],
		'fullscreen_enter' => [],
		'fullscreen_exit' => [],
		'subtitle_change' => ['from', 'to'],
		'quality_change' => ['from', 'to', 'auto'],
		'buffering_start' => [],
		'buffering_end' => ['seconds'],
		'error' => ['reason'],
		// Tab switches only while the film plays (the player decides); window
		// focus isn't tracked: a paused viewer isn't watching anyway.
		// Older events of the types window_blur/window_focus stay readable.
		'visibility_hidden' => [],
		'visibility_visible' => [],
		'page_leave' => [],
		'playback_stopped' => ['reason'],
	];

	public const MAX_BATCH = 50;
	public const MAX_EVENTS_PER_HOUR = 1500;
	private const MAX_PAYLOAD_CHARS = 500;
	/** Played time may exceed elapsed wall-clock time by this factor (timer jitter). */
	private const PLAYED_SLACK = 1.25;
	private const PLAYED_EXTRA_SECONDS = 3;

	private ICache $cache;

	public function __construct(
		private EventLogger $events,
		private ViewerSessionMapper $sessions,
		private IDBConnection $db,
		private ITimeFactory $time,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('screenings-tracking');
	}

	/**
	 * @return int number of events stored
	 */
	public function recordEvents(StreamContext $ctx, mixed $events): int {
		if (!is_array($events)) {
			return 0;
		}
		$events = array_slice($events, 0, self::MAX_BATCH);
		if (!$this->withinRateLimit($ctx->session->getId(), count($events))) {
			return 0;
		}

		$session = $ctx->session;
		$durationS = ($ctx->screening->getDurationMs() ?? 0) / 1000;
		$stored = 0;
		$sessionChanged = false;

		foreach ($events as $event) {
			if (!is_array($event) || !is_string($event['type'] ?? null) || !isset(self::EVENT_TYPES[$event['type']])) {
				continue;
			}
			$type = $event['type'];
			$payload = $this->payload($type, $event['data'] ?? null);
			$position = isset($event['position']) && is_numeric($event['position'])
				? max(0.0, $durationS > 0 ? min((float)$event['position'], $durationS + 5) : (float)$event['position'])
				: null;
			$clientTs = isset($event['ts']) && is_numeric($event['ts']) ? (int)$event['ts'] : null;

			$this->events->log($ctx->screening->getId(), $ctx->invite->getId(), $session->getId(), $type, $payload, $position, $clientTs);
			$stored++;

			if ($type === 'player_ready' && isset($payload['screen'][0], $payload['screen'][1])) {
				$session->setScreenW((int)$payload['screen'][0]);
				$session->setScreenH((int)$payload['screen'][1]);
				$sessionChanged = true;
			}
			// Not ended_at: that marks sessions replaced by a newer one, and the
			// final heartbeat (sent alongside) must still be accepted.
			if ($type === 'page_leave') {
				$session->setPlaying(false);
				$sessionChanged = true;
			}
		}

		if ($sessionChanged) {
			$this->sessions->update($session);
		}
		return $stored;
	}

	/**
	 * Adds the intervals played since the previous heartbeat. Call before the
	 * heartbeat updates last_heartbeat_at.
	 */
	public function recordRanges(ViewerSession $session, float $durationS, mixed $ranges): void {
		$new = WatchRanges::sanitize($ranges, $durationS);
		if ($new === []) {
			return;
		}
		$elapsed = $this->time->getTime() - ($session->getLastHeartbeatAt() ?? $session->getStartedAt());
		$plausible = max(0, $elapsed) * self::PLAYED_SLACK + self::PLAYED_EXTRA_SECONDS;
		if (WatchRanges::totalSeconds(WatchRanges::merge($new)) > $plausible) {
			// More "played" time than wall-clock time allows: not a real player.
			return;
		}

		$existing = $this->ranges($session->getId());
		$merged = WatchRanges::merge($existing, $new);
		if ($merged === $existing) {
			return;
		}

		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('scr_watch_ranges')
				->where($qb->expr()->eq('session_id', $qb->createNamedParameter($session->getId(), IQueryBuilder::PARAM_INT)))
				->executeStatement();
			foreach ($merged as [$start, $end]) {
				$insert = $this->db->getQueryBuilder();
				$insert->insert('scr_watch_ranges')
					->values([
						'session_id' => $insert->createNamedParameter($session->getId(), IQueryBuilder::PARAM_INT),
						'start_s' => $insert->createNamedParameter($start),
						'end_s' => $insert->createNamedParameter($end),
					])
					->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @return list<array{float, float}>
	 */
	public function ranges(int $sessionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('start_s', 'end_s')
			->from('scr_watch_ranges')
			->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
			->orderBy('start_s');
		$result = $qb->executeQuery();
		$ranges = [];
		while ($row = $result->fetch()) {
			$ranges[] = [(float)$row['start_s'], (float)$row['end_s']];
		}
		$result->closeCursor();
		return $ranges;
	}

	/**
	 * Keeps only whitelisted keys with scalar (or short scalar list) values.
	 */
	private function payload(string $type, mixed $data): array {
		if (!is_array($data)) {
			return [];
		}
		$payload = [];
		foreach (self::EVENT_TYPES[$type] as $key) {
			if (!array_key_exists($key, $data)) {
				continue;
			}
			$value = $data[$key];
			if (is_string($value)) {
				$payload[$key] = mb_substr($value, 0, 64);
			} elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
				$payload[$key] = $value;
			} elseif (is_array($value) && count($value) <= 4 && array_filter($value, 'is_numeric') === $value) {
				$payload[$key] = array_values(array_map('floatval', $value));
			}
		}
		return strlen((string)json_encode($payload)) > self::MAX_PAYLOAD_CHARS ? [] : $payload;
	}

	private function withinRateLimit(int $sessionId, int $count): bool {
		$key = 'events-' . $sessionId . '-' . intdiv($this->time->getTime(), 3600);
		$used = (int)($this->cache->get($key) ?? 0);
		if ($used + $count > self::MAX_EVENTS_PER_HOUR) {
			return false;
		}
		$this->cache->set($key, $used + $count, 3600);
		return true;
	}
}

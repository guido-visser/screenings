<?php

declare(strict_types=1);

namespace OCA\Screenings\Dashboard;

use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\InviteMapper;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Db\ViewerSession;
use OCA\Screenings\Db\ViewerSessionMapper;
use OCA\Screenings\Tracking\WatchRanges;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read side of tracking: what the owner's dashboard shows.
 */
class DashboardService {
	/** A session is "watching now" when its last heartbeat is this recent. */
	public const LIVE_SECONDS = 20;
	public const EVENTS_PAGE = 300;
	/** Code requests without an invite read per screening; the rate limits keep this small. */
	public const PENDING_EVENTS = 5000;

	public function __construct(
		private InviteMapper $invites,
		private ViewerSessionMapper $sessions,
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	public function overview(Screening $screening): array {
		$invites = $this->invites->findForScreening($screening->getId());
		$inviteIds = array_map(fn (Invite $i) => $i->getId(), $invites);
		$sessions = $this->sessions->findForInvites($inviteIds);
		$rangesBySession = $this->rangesForSessions(array_map(fn (ViewerSession $s) => $s->getId(), $sessions));
		$opens = $this->countEvents($inviteIds, 'page_open');
		$durationS = ($screening->getDurationMs() ?? 0) / 1000;

		$sessionsByInvite = [];
		foreach ($sessions as $session) {
			$sessionsByInvite[$session->getInviteId()][] = $session;
		}

		$viewers = [];
		$viewerRanges = [];
		$watchingNow = 0;
		foreach ($invites as $invite) {
			$own = $sessionsByInvite[$invite->getId()] ?? [];
			$ranges = [];
			foreach ($own as $session) {
				$ranges = WatchRanges::merge($ranges, $rangesBySession[$session->getId()] ?? []);
			}
			if ($ranges !== []) {
				$viewerRanges[] = $ranges;
			}
			$live = $this->liveState($own);
			if ($live !== null) {
				$watchingNow++;
			}
			$viewers[] = $this->viewerSummary($invite, $own, $ranges, $opens[$invite->getId()] ?? 0, $durationS, $live);
		}

		$started = array_filter($viewers, fn (array $v) => $v['watchedSeconds'] > 0);
		return [
			'durationS' => $durationS,
			'totals' => [
				'invites' => count($invites),
				'opened' => count(array_filter($viewers, fn (array $v) => $v['opens'] > 0)),
				'started' => count($started),
				'watchingNow' => $watchingNow,
				'averagePercent' => $started === [] ? null : round(array_sum(array_column($started, 'percent')) / count($started), 1),
				'totalWatchedSeconds' => round(array_sum(array_column($viewers, 'watchedSeconds')), 1),
			],
			'heatmap' => [
				'bucketSeconds' => Heatmap::bucketSeconds($durationS),
				'buckets' => Heatmap::buckets($viewerRanges, $durationS),
				'max' => count($viewerRanges),
			],
			'viewers' => $viewers,
			'pending' => $this->pendingVisitors($screening->getId(), $invites),
		];
	}

	/**
	 * Just the live state per invite, for frequent polling.
	 */
	public function live(Screening $screening): array {
		$invites = $this->invites->findForScreening($screening->getId());
		$sessions = $this->sessions->findForInvites(array_map(fn (Invite $i) => $i->getId(), $invites));
		$byInvite = [];
		foreach ($sessions as $session) {
			$byInvite[$session->getInviteId()][] = $session;
		}
		$live = [];
		foreach ($invites as $invite) {
			$state = $this->liveState($byInvite[$invite->getId()] ?? []);
			if ($state !== null) {
				$live[$invite->getId()] = $state;
			}
		}
		return ['now' => $this->time->getTime(), 'live' => (object)$live];
	}

	/**
	 * One viewer: sessions with device and watched ranges, a "how often
	 * watched" heatmap, and the event log (newest first, paged by id).
	 */
	public function viewer(Screening $screening, Invite $invite, ?int $beforeEventId = null): array {
		$sessions = $this->sessions->findForInvites([$invite->getId()]);
		$rangesBySession = $this->rangesForSessions(array_map(fn (ViewerSession $s) => $s->getId(), $sessions));
		$durationS = ($screening->getDurationMs() ?? 0) / 1000;
		$merged = [];
		foreach ($rangesBySession as $ranges) {
			$merged = WatchRanges::merge($merged, $ranges);
		}
		$opens = $this->countEvents([$invite->getId()], 'page_open')[$invite->getId()] ?? 0;
		$live = $this->liveState($sessions);

		$sessionRows = array_map(function (ViewerSession $s) use ($rangesBySession): array {
			$ranges = $rangesBySession[$s->getId()] ?? [];
			return $s->jsonSerialize() + [
				'watchedSeconds' => round(WatchRanges::totalSeconds($ranges), 1),
				'ranges' => $ranges,
				'live' => $this->isLive($s),
			];
		}, $sessions);

		return [
			'durationS' => $durationS,
			'viewer' => $this->viewerSummary($invite, $sessions, $merged, $opens, $durationS, $live),
			'heatmap' => [
				'bucketSeconds' => Heatmap::bucketSeconds($durationS),
				// Per session: darker means watched in more sessions (re-watched).
				'buckets' => Heatmap::buckets(array_values(array_filter($rangesBySession)), $durationS),
				'max' => max(1, count(array_filter($rangesBySession))),
			],
			'sessions' => $sessionRows,
			'events' => $this->events($invite->getId(), $beforeEventId),
		];
	}

	/**
	 * @param ViewerSession[] $sessions
	 */
	private function viewerSummary(Invite $invite, array $sessions, array $ranges, int $opens, float $durationS, ?array $live): array {
		$watched = WatchRanges::totalSeconds($ranges);
		$lastSeen = null;
		$firstSeen = null;
		foreach ($sessions as $session) {
			$seen = $session->getLastHeartbeatAt() ?? $session->getStartedAt();
			$lastSeen = max($lastSeen ?? 0, $seen);
			$firstSeen = min($firstSeen ?? PHP_INT_MAX, $session->getStartedAt());
		}
		$latest = $sessions[0] ?? null;
		return [
			'inviteId' => $invite->getId(),
			'name' => $invite->getName(),
			'email' => $invite->getEmail(),
			'source' => $invite->getSource(),
			'revoked' => $invite->isRevoked(),
			'invitedAt' => $invite->getInvitedAt(),
			'opens' => $opens,
			'sessions' => count($sessions),
			'firstSeenAt' => $firstSeen,
			'lastSeenAt' => $lastSeen,
			'watchedSeconds' => round($watched, 1),
			'percent' => $durationS > 0 ? round(min(100, $watched / $durationS * 100), 1) : 0,
			'furthestS' => $ranges === [] ? null : round(max(array_column($ranges, 1)), 1),
			'device' => $latest === null ? null : [
				'browser' => $latest->getBrowser(),
				'os' => $latest->getOs(),
				'deviceType' => $latest->getDeviceType(),
				'country' => $latest->getCountry(),
				'city' => $latest->getCity(),
			],
			'live' => $live,
		];
	}

	/**
	 * @param ViewerSession[] $sessions newest first
	 */
	private function liveState(array $sessions): ?array {
		foreach ($sessions as $session) {
			if ($this->isLive($session)) {
				return [
					'sessionId' => $session->getId(),
					'playing' => (bool)$session->getPlaying(),
					'positionS' => $session->getLastPositionS(),
					'subtitle' => $session->getCurrentSubtitle(),
					'quality' => $session->getCurrentQuality(),
					'lastHeartbeatAt' => $session->getLastHeartbeatAt(),
				];
			}
		}
		return null;
	}

	private function isLive(ViewerSession $session): bool {
		return $session->getEndedAt() === null
			&& $session->getLastHeartbeatAt() !== null
			&& $this->time->getTime() - $session->getLastHeartbeatAt() <= self::LIVE_SECONDS;
	}

	/**
	 * @param int[] $sessionIds
	 * @return array<int, list<array{float, float}>>
	 */
	private function rangesForSessions(array $sessionIds): array {
		$ranges = [];
		foreach (array_chunk($sessionIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('session_id', 'start_s', 'end_s')
				->from('scr_watch_ranges')
				->where($qb->expr()->in('session_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('start_s');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$ranges[(int)$row['session_id']][] = [(float)$row['start_s'], (float)$row['end_s']];
			}
			$result->closeCursor();
		}
		return $ranges;
	}

	/**
	 * @param int[] $inviteIds
	 * @return array<int, int> invite id => count
	 */
	private function countEvents(array $inviteIds, string $type): array {
		if ($inviteIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('invite_id')
			->selectAlias($qb->func()->count('id'), 'cnt')
			->from('scr_events')
			->where($qb->expr()->in('invite_id', $qb->createNamedParameter($inviteIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->groupBy('invite_id');
		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(int)$row['invite_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * Open-link visitors who asked for a code but never confirmed it. Their
	 * events have no invite yet, only the email address in the payload.
	 *
	 * @param Invite[] $invites
	 * @return list<array{email: string, name: ?string, codes: int, failed: int, lastAt: int}> newest first
	 */
	private function pendingVisitors(int $screeningId, array $invites): array {
		$known = [];
		foreach ($invites as $invite) {
			$known[mb_strtolower($invite->getEmail())] = true;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('type', 'payload', 'server_ts_ms')
			->from('scr_events')
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('invite_id'))
			->andWhere($qb->expr()->in('type', $qb->createNamedParameter(['otp_requested', 'otp_fail'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'DESC')
			->setMaxResults(self::PENDING_EVENTS);
		$result = $qb->executeQuery();
		$visitors = [];
		while ($row = $result->fetch()) {
			$payload = $row['payload'] === null ? null : json_decode($row['payload'], true);
			$email = is_array($payload) && is_string($payload['email'] ?? null) ? $payload['email'] : null;
			if ($email === null || isset($known[$email])) {
				continue;
			}
			$visitor = $visitors[$email] ?? ['email' => $email, 'name' => null, 'codes' => 0, 'failed' => 0, 'lastAt' => 0];
			if ($row['type'] === 'otp_requested') {
				$visitor['codes']++;
				// Newest first, so the first name seen is the latest one given.
				$visitor['name'] ??= is_string($payload['name'] ?? null) ? $payload['name'] : null;
			} else {
				$visitor['failed']++;
			}
			$visitor['lastAt'] = max($visitor['lastAt'], (int)$row['server_ts_ms']);
			$visitors[$email] = $visitor;
		}
		$result->closeCursor();
		return array_values($visitors);
	}

	private function events(int $inviteId, ?int $beforeId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'session_id', 'type', 'position_s', 'payload', 'server_ts_ms')
			->from('scr_events')
			->where($qb->expr()->eq('invite_id', $qb->createNamedParameter($inviteId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults(self::EVENTS_PAGE + 1);
		if ($beforeId !== null) {
			$qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$events = [];
		while ($row = $result->fetch()) {
			$events[] = [
				'id' => (int)$row['id'],
				'sessionId' => $row['session_id'] === null ? null : (int)$row['session_id'],
				'type' => $row['type'],
				'positionS' => $row['position_s'] === null ? null : round((float)$row['position_s'], 1),
				'data' => $row['payload'] === null ? null : json_decode($row['payload'], true),
				'at' => (int)$row['server_ts_ms'],
			];
		}
		$result->closeCursor();
		$more = count($events) > self::EVENTS_PAGE;
		return ['items' => array_slice($events, 0, self::EVENTS_PAGE), 'more' => $more];
	}
}

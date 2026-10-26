<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Deletes viewer sessions, events, watched ranges and OTP codes when an
 * invite or a whole screening is deleted, or when they pass the screening's
 * retention period. Viewer notes go with the invite or screening, but not
 * with the retention period: the viewer gave them to the owner on purpose.
 */
class TrackingCleanupService {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param int[] $inviteIds
	 */
	public function deleteForInvites(array $inviteIds): void {
		if ($inviteIds === []) {
			return;
		}
		foreach (array_chunk($inviteIds, 500) as $chunk) {
			$sessionIds = $this->sessionIds($chunk);
			foreach (array_chunk($sessionIds, 500) as $sessionChunk) {
				$this->deleteIn('scr_watch_ranges', 'session_id', $sessionChunk);
			}
			$this->deleteIn('scr_events', 'invite_id', $chunk);
			$this->deleteIn('scr_sessions', 'invite_id', $chunk);
			$this->deleteIn('scr_notes', 'invite_id', $chunk);
		}
	}

	public function deleteForScreening(int $screeningId, array $inviteIds): void {
		$this->deleteForInvites($inviteIds);
		// Events logged before an invite was known (e.g. open-link page views).
		$this->deleteEq('scr_events', 'screening_id', $screeningId);
		$this->deleteEq('scr_otp', 'screening_id', $screeningId);
		$this->deleteEq('scr_notes', 'screening_id', $screeningId);
	}

	/**
	 * Deletes tracking data older than $cutoff (unix seconds). A session counts
	 * by its last sign of life, so one that is still being watched is kept.
	 *
	 * @param int[] $inviteIds
	 * @return int number of deleted sessions
	 */
	public function deleteOlderThan(int $screeningId, array $inviteIds, int $cutoff): int {
		$deleted = 0;
		foreach (array_chunk($inviteIds, 500) as $chunk) {
			$sessionIds = $this->staleSessionIds($chunk, $cutoff);
			foreach (array_chunk($sessionIds, 500) as $sessionChunk) {
				$this->deleteIn('scr_watch_ranges', 'session_id', $sessionChunk);
				$this->deleteIn('scr_events', 'session_id', $sessionChunk);
				$this->deleteIn('scr_sessions', 'id', $sessionChunk);
			}
			$deleted += count($sessionIds);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('scr_events')
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('server_ts_ms', $qb->createNamedParameter($cutoff * 1000, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return $deleted;
	}

	/**
	 * @param int[] $inviteIds
	 * @return int[]
	 */
	private function staleSessionIds(array $inviteIds, int $cutoff): array {
		$qb = $this->db->getQueryBuilder();
		$before = $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT);
		$qb->select('id')
			->from('scr_sessions')
			->where($qb->expr()->in('invite_id', $qb->createNamedParameter($inviteIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->lt('last_heartbeat_at', $before),
				$qb->expr()->andX(
					$qb->expr()->isNull('last_heartbeat_at'),
					$qb->expr()->lt('started_at', $before),
				),
			));
		return $this->fetchIds($qb);
	}

	/**
	 * @param int[] $inviteIds
	 * @return int[]
	 */
	private function sessionIds(array $inviteIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('scr_sessions')
			->where($qb->expr()->in('invite_id', $qb->createNamedParameter($inviteIds, IQueryBuilder::PARAM_INT_ARRAY)));
		return $this->fetchIds($qb);
	}

	/**
	 * @return int[]
	 */
	private function fetchIds(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		return $ids;
	}

	private function deleteIn(string $table, string $column, array $ids): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->in($column, $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	private function deleteEq(string $table, string $column, int $value): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}

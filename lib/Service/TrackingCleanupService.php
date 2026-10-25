<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Deletes viewer sessions, events, watched ranges and OTP codes when an
 * invite or a whole screening is deleted.
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
		}
	}

	public function deleteForScreening(int $screeningId, array $inviteIds): void {
		$this->deleteForInvites($inviteIds);
		// Events logged before an invite was known (e.g. open-link page views).
		$this->deleteEq('scr_events', 'screening_id', $screeningId);
		$this->deleteEq('scr_otp', 'screening_id', $screeningId);
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

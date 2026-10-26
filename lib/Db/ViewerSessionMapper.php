<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ViewerSession>
 */
class ViewerSessionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'scr_sessions', ViewerSession::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): ViewerSession {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param int[] $inviteIds
	 * @return ViewerSession[] newest first
	 */
	public function findForInvites(array $inviteIds): array {
		if ($inviteIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('invite_id', $qb->createNamedParameter($inviteIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('started_at', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * The invite's most recently active session that got past the opening
	 * seconds, for "continue where you left off".
	 */
	public function findLastWatched(int $inviteId, float $minPositionS): ?ViewerSession {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('invite_id', $qb->createNamedParameter($inviteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('last_position_s', $qb->createNamedParameter($minPositionS)))
			->andWhere($qb->expr()->isNotNull('last_heartbeat_at'))
			->orderBy('last_heartbeat_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Ends every still-open session of an invite.
	 */
	public function endOpenForInvite(int $inviteId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('ended_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('playing', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('invite_id', $qb->createNamedParameter($inviteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('ended_at'))
			->executeStatement();
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Invite>
 */
class InviteMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'scr_invites', Invite::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Invite {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByToken(string $token): Invite {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		return $this->findEntity($qb);
	}

	/**
	 * The screening's invite for an email address (case-insensitive).
	 *
	 * @throws DoesNotExistException
	 */
	public function findByEmail(int $screeningId, string $email): Invite {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq($qb->func()->lower('email'), $qb->createNamedParameter(mb_strtolower($email))))
			->orderBy('created_at', 'ASC')
			->setMaxResults(1);
		return $this->findEntity($qb);
	}

	/**
	 * @return Invite[]
	 */
	public function findForScreening(int $screeningId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * @return array<int, int> screening id => invite count
	 */
	public function countByScreening(array $screeningIds): array {
		if ($screeningIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('screening_id')
			->selectAlias($qb->func()->count('id'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->in('screening_id', $qb->createNamedParameter($screeningIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('screening_id');
		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(int)$row['screening_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();
		return $counts;
	}
}

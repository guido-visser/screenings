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
	 * Names of a screening's public visitors ("Visitor 14"), for numbering.
	 *
	 * @return list<string>
	 */
	public function publicVisitorNames(int $screeningId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('name')
			->from($this->getTableName())
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source', $qb->createNamedParameter(Invite::SOURCE_PUBLIC)));
		$result = $qb->executeQuery();
		$names = [];
		while ($row = $result->fetch()) {
			$names[] = (string)$row['name'];
		}
		$result->closeCursor();
		return $names;
	}

	/**
	 * Revokes every visitor of a screening's public link (a new link was made).
	 */
	public function revokePublicVisitors(int $screeningId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('revoked_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source', $qb->createNamedParameter(Invite::SOURCE_PUBLIC)))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->executeStatement();
	}

	/**
	 * @return array<int, int> screening id => invite count (public visitors not included)
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
			->andWhere($qb->expr()->neq('source', $qb->createNamedParameter(Invite::SOURCE_PUBLIC)))
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

<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Otp>
 */
class OtpMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'scr_otp', Otp::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findLatest(int $screeningId, string $email): Otp {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('email', $qb->createNamedParameter($email)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		return $this->findEntity($qb);
	}

	public function countSince(int $screeningId, string $email, int $since): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('email', $qb->createNamedParameter($email)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	public function deleteFor(int $screeningId, string $email): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('email', $qb->createNamedParameter($email)))
			->executeStatement();
	}

	public function deleteExpired(int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}

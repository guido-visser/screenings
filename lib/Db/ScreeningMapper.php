<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Screening>
 */
class ScreeningMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'scr_screenings', Screening::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findForOwner(int $id, string $ownerUid): Screening {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByOpenLinkToken(string $token): Screening {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('open_link_token', $qb->createNamedParameter($token)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Screening[]
	 */
	public function findAllForOwner(string $ownerUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}
}

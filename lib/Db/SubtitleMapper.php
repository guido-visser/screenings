<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Subtitle>
 */
class SubtitleMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'scr_subtitles', Subtitle::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Subtitle {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Subtitle[]
	 */
	public function findForScreening(int $screeningId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	public function clearDefault(int $screeningId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('is_default', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}

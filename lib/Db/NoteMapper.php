<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Note>
 */
class NoteMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'scr_notes', Note::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Note {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Note[] in film order
	 */
	public function findForInvite(int $inviteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('invite_id', $qb->createNamedParameter($inviteId, IQueryBuilder::PARAM_INT)))
			->orderBy('position_s')
			->addOrderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * @return Note[] in film order
	 */
	public function findForScreening(int $screeningId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->orderBy('position_s')
			->addOrderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * Notes per invite of a screening.
	 *
	 * @return array<int, int> invite id => count
	 */
	public function countByInvite(int $screeningId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('invite_id')
			->selectAlias($qb->func()->count('id'), 'notes')
			->from($this->getTableName())
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->groupBy('invite_id');
		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(int)$row['invite_id']] = (int)$row['notes'];
		}
		$result->closeCursor();
		return $counts;
	}
}

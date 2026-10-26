<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<MailTemplate>
 */
class MailTemplateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'scr_mail_templates', MailTemplate::class);
	}

	/**
	 * The owner's default design (screening null) or a screening's own.
	 *
	 * @throws DoesNotExistException
	 */
	public function findFor(string $ownerUid, ?int $screeningId): MailTemplate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		if ($screeningId === null) {
			$qb->andWhere($qb->expr()->isNull('screening_id'));
		} else {
			$qb->andWhere($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntity($qb);
	}

	public function deleteForScreening(int $screeningId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('screening_id', $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function deleteForOwner(string $ownerUid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
			->executeStatement();
	}
}

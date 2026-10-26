<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method ?int getScreeningId()
 * @method void setScreeningId(?int $screeningId)
 * @method string getSubject()
 * @method void setSubject(string $subject)
 * @method string getHtml()
 * @method void setHtml(string $html)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class MailTemplate extends Entity {
	protected $ownerUid;
	protected $screeningId;
	protected $subject;
	protected $html;
	protected $updatedAt;

	public function __construct() {
		$this->addType('screeningId', 'integer');
		$this->addType('updatedAt', 'integer');
	}
}

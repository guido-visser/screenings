<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A note a viewer left at a moment in the film.
 *
 * @method int getScreeningId()
 * @method void setScreeningId(int $screeningId)
 * @method int getInviteId()
 * @method void setInviteId(int $inviteId)
 * @method ?int getSessionId()
 * @method void setSessionId(?int $sessionId)
 * @method float getPositionS()
 * @method void setPositionS(float $positionS)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Note extends Entity implements JsonSerializable {
	protected $screeningId;
	protected $inviteId;
	protected $sessionId;
	protected $positionS;
	protected $body;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('screeningId', 'integer');
		$this->addType('inviteId', 'integer');
		$this->addType('sessionId', 'integer');
		$this->addType('positionS', 'float');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/**
	 * What the viewer sees of their own notes.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'position' => $this->positionS,
			'body' => $this->body,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}

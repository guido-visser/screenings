<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A viewer's answer to one closing question; stored as text ("4" for a
 * rating, the option for a choice).
 *
 * @method int getScreeningId()
 * @method void setScreeningId(int $screeningId)
 * @method int getInviteId()
 * @method void setInviteId(int $inviteId)
 * @method int getQuestionId()
 * @method void setQuestionId(int $questionId)
 * @method string getValue()
 * @method void setValue(string $value)
 * @method int getAnsweredAt()
 * @method void setAnsweredAt(int $answeredAt)
 */
class Answer extends Entity {
	protected $screeningId;
	protected $inviteId;
	protected $questionId;
	protected $value;
	protected $answeredAt;

	public function __construct() {
		$this->addType('screeningId', 'integer');
		$this->addType('inviteId', 'integer');
		$this->addType('questionId', 'integer');
		$this->addType('answeredAt', 'integer');
	}
}

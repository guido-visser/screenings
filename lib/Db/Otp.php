<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A one-time code mailed to someone using a screening's open link.
 *
 * @method int getScreeningId()
 * @method void setScreeningId(int $screeningId)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getCodeHash()
 * @method void setCodeHash(string $codeHash)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method int getExpiresAt()
 * @method void setExpiresAt(int $expiresAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Otp extends Entity {
	protected $screeningId;
	protected $email;
	protected $name;
	protected $codeHash;
	protected $attempts;
	protected $expiresAt;
	protected $createdAt;

	public function __construct() {
		$this->addType('screeningId', 'integer');
		$this->addType('attempts', 'integer');
		$this->addType('expiresAt', 'integer');
		$this->addType('createdAt', 'integer');
	}
}

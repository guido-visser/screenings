<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getScreeningId()
 * @method void setScreeningId(int $screeningId)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method ?int getEmailVerifiedAt()
 * @method void setEmailVerifiedAt(?int $emailVerifiedAt)
 * @method ?int getInvitedAt()
 * @method void setInvitedAt(?int $invitedAt)
 * @method ?int getRevokedAt()
 * @method void setRevokedAt(?int $revokedAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Invite extends Entity implements JsonSerializable {
	public const SOURCE_INVITED = 'invited';
	public const SOURCE_OPEN_LINK = 'open_link';

	protected $screeningId;
	protected $token;
	protected $name;
	protected $email;
	protected $source;
	protected $emailVerifiedAt;
	protected $invitedAt;
	protected $revokedAt;
	protected $createdAt;

	public function __construct() {
		$this->addType('screeningId', 'integer');
		$this->addType('emailVerifiedAt', 'integer');
		$this->addType('invitedAt', 'integer');
		$this->addType('revokedAt', 'integer');
		$this->addType('createdAt', 'integer');
	}

	public function isRevoked(): bool {
		return $this->revokedAt !== null;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'screeningId' => $this->screeningId,
			'token' => $this->token,
			'name' => $this->name,
			'email' => $this->email,
			'source' => $this->source,
			'emailVerifiedAt' => $this->emailVerifiedAt,
			'invitedAt' => $this->invitedAt,
			'revokedAt' => $this->revokedAt,
			'createdAt' => $this->createdAt,
		];
	}
}

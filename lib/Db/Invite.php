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
 * @method ?bool getAllowNotes()
 * @method void setAllowNotes(bool $allowNotes)
 * @method ?bool getAskQuestions()
 * @method void setAskQuestions(bool $askQuestions)
 */
class Invite extends Entity implements JsonSerializable {
	public const SOURCE_INVITED = 'invited';
	public const SOURCE_OPEN_LINK = 'open_link';
	/** An anonymous visitor of the public link ("Visitor 14", no email). */
	public const SOURCE_PUBLIC = 'public';

	protected $screeningId;
	protected $token;
	protected $name;
	protected $email;
	protected $source;
	protected $emailVerifiedAt;
	protected $invitedAt;
	protected $revokedAt;
	protected $createdAt;
	protected $allowNotes;
	protected $askQuestions;

	public function __construct() {
		$this->addType('screeningId', 'integer');
		$this->addType('emailVerifiedAt', 'integer');
		$this->addType('invitedAt', 'integer');
		$this->addType('revokedAt', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('allowNotes', 'boolean');
		$this->addType('askQuestions', 'boolean');
	}

	public function isRevoked(): bool {
		return $this->revokedAt !== null;
	}

	/**
	 * Open-link and public visitors never give feedback; invited viewers only
	 * when the owner turned it on for them.
	 */
	public function mayLeaveNotes(): bool {
		return $this->source === self::SOURCE_INVITED && (bool)$this->allowNotes;
	}

	public function mayAnswerQuestions(): bool {
		return $this->source === self::SOURCE_INVITED && (bool)$this->askQuestions;
	}

	public function isPublicVisitor(): bool {
		return $this->source === self::SOURCE_PUBLIC;
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
			'allowNotes' => (bool)$this->allowNotes,
			'askQuestions' => (bool)$this->askQuestions,
		];
	}
}

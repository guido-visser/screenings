<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\InviteMapper;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Mail\IMailer;

class InviteService {
	public function __construct(
		private InviteMapper $mapper,
		private TrackingCleanupService $cleanup,
		private TokenGenerator $tokens,
		private IMailer $mailer,
		private ITimeFactory $time,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return Invite[]
	 */
	public function listForScreening(int $screeningId): array {
		return $this->mapper->findForScreening($screeningId);
	}

	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Invite {
		try {
			return $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Invite not found');
		}
	}

	/**
	 * @throws ValidationException
	 */
	public function create(int $screeningId, string $name, string $email, string $source = Invite::SOURCE_INVITED): Invite {
		$name = trim($name);
		$email = trim($email);
		if ($name === '') {
			throw new ValidationException('A name is required', 'name');
		}
		if (mb_strlen($name) > 255) {
			throw new ValidationException('The name is too long', 'name');
		}
		if (!$this->mailer->validateMailAddress($email)) {
			throw new ValidationException('Enter a valid email address', 'email');
		}

		$invite = new Invite();
		$invite->setScreeningId($screeningId);
		$invite->setToken($this->tokens->linkToken());
		$invite->setName($name);
		$invite->setEmail($email);
		$invite->setSource($source);
		$invite->setCreatedAt($this->time->getTime());
		return $this->mapper->insert($invite);
	}

	public function setRevoked(Invite $invite, bool $revoked): Invite {
		$invite->setRevokedAt($revoked ? $this->time->getTime() : null);
		return $this->mapper->update($invite);
	}

	/**
	 * Deletes the invite with all of its tracking data.
	 */
	public function delete(Invite $invite): void {
		$this->db->beginTransaction();
		try {
			$this->cleanup->deleteForInvites([$invite->getId()]);
			$this->mapper->delete($invite);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Db\InviteMapper;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Db\ScreeningMapper;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FileNotFoundException;
use OCP\IDBConnection;
use OCP\Security\IHasher;

class ScreeningService {
	public const MAX_TITLE = 255;
	public const MAX_RETENTION_DAYS = 3650;

	public function __construct(
		private ScreeningMapper $mapper,
		private InviteMapper $inviteMapper,
		private SubtitleService $subtitles,
		private TrackingCleanupService $cleanup,
		private DeliveryModes $modes,
		private TokenGenerator $tokens,
		private IHasher $hasher,
		private IRootFolder $rootFolder,
		private ITimeFactory $time,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<array> screenings with invite counts and source paths
	 */
	public function listForOwner(string $uid): array {
		$screenings = $this->mapper->findAllForOwner($uid);
		$counts = $this->inviteMapper->countByScreening(array_map(fn (Screening $s) => $s->getId(), $screenings));
		return array_map(fn (Screening $s) => $this->toArray($s) + ['inviteCount' => $counts[$s->getId()] ?? 0], $screenings);
	}

	/**
	 * @return array<int, int> source file id => newest screening id
	 */
	public function idsBySource(string $uid): array {
		return $this->mapper->findIdsBySourceForOwner($uid);
	}

	/**
	 * @throws NotFoundException
	 */
	public function get(int $id, string $uid): Screening {
		try {
			return $this->mapper->findForOwner($id, $uid);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Screening not found');
		}
	}

	public function toArray(Screening $screening): array {
		return $screening->jsonSerialize() + [
			'sourcePath' => $this->sourcePath($screening),
		];
	}

	/**
	 * @throws ValidationException
	 */
	public function create(string $uid, array $data): Screening {
		$now = $this->time->getTime();
		$screening = new Screening();
		$screening->setOwnerUid($uid);
		$screening->setTitle('');
		$screening->setDeliveryMode(Screening::MODE_MP4);
		$screening->setMediaStatus(Screening::MEDIA_NONE);
		$screening->setMediaProgress(0);
		$screening->setWatermark(false);
		$screening->setOpenLinkEnabled(false);
		$screening->setOpenLinkToken($this->tokens->linkToken());
		$screening->setRetentionDays(365);
		$screening->setCreatedAt($now);
		$screening->setUpdatedAt($now);

		$this->apply($screening, $data + ['title' => ''], $uid);
		return $this->mapper->insert($screening);
	}

	/**
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function update(int $id, string $uid, array $data): Screening {
		$screening = $this->get($id, $uid);
		$this->apply($screening, $data, $uid);
		$screening->setUpdatedAt($this->time->getTime());
		return $this->mapper->update($screening);
	}

	/**
	 * @throws NotFoundException
	 */
	public function regenerateOpenLink(int $id, string $uid): Screening {
		$screening = $this->get($id, $uid);
		$screening->setOpenLinkToken($this->tokens->linkToken());
		$screening->setUpdatedAt($this->time->getTime());
		return $this->mapper->update($screening);
	}

	/**
	 * Deletes the screening with its invites, subtitles and all tracking data.
	 *
	 * @throws NotFoundException
	 */
	public function delete(int $id, string $uid): void {
		$screening = $this->get($id, $uid);
		$invites = $this->inviteMapper->findForScreening($id);

		$this->db->beginTransaction();
		try {
			$this->cleanup->deleteForScreening($id, array_map(fn ($i) => $i->getId(), $invites));
			foreach ($invites as $invite) {
				$this->inviteMapper->delete($invite);
			}
			$this->subtitles->deleteAllForScreening($id);
			$this->mapper->delete($screening);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Applies the fields present in $data. Absent keys are left unchanged.
	 *
	 * @throws ValidationException
	 */
	private function apply(Screening $screening, array $data, string $uid): void {
		if (array_key_exists('title', $data)) {
			$title = trim((string)$data['title']);
			if ($title === '') {
				throw new ValidationException('A title is required', 'title');
			}
			if (mb_strlen($title) > self::MAX_TITLE) {
				throw new ValidationException('The title is too long', 'title');
			}
			$screening->setTitle($title);
		}

		if (array_key_exists('description', $data)) {
			$description = trim((string)($data['description'] ?? ''));
			$screening->setDescription($description === '' ? null : $description);
		}

		$mediaChanged = false;
		if (array_key_exists('deliveryMode', $data) && $data['deliveryMode'] !== $screening->getDeliveryMode()) {
			$mode = (string)$data['deliveryMode'];
			if (!$this->modes->isAvailable($mode)) {
				throw new ValidationException('This delivery mode is not available on this server', 'deliveryMode');
			}
			$screening->setDeliveryMode($mode);
			$mediaChanged = true;
		}

		if (array_key_exists('sourcePath', $data)) {
			$path = $data['sourcePath'];
			if ($path === null || $path === '') {
				$screening->setSourceFileId(null);
			} else {
				$screening->setSourceFileId($this->resolveSource((string)$path, $uid)->getId());
			}
			$mediaChanged = true;
		}

		if ($mediaChanged) {
			$this->validateSourceForMode($screening, $uid);
			$this->resetMedia($screening);
		}

		if (array_key_exists('password', $data)) {
			$password = (string)($data['password'] ?? '');
			$screening->setPasswordHash($password === '' ? null : $this->hasher->hash($password));
		}

		if (array_key_exists('expiresAt', $data)) {
			$expiresAt = $data['expiresAt'];
			if ($expiresAt !== null && (!is_numeric($expiresAt) || (int)$expiresAt <= 0)) {
				throw new ValidationException('Invalid expiry date', 'expiresAt');
			}
			$screening->setExpiresAt($expiresAt === null ? null : (int)$expiresAt);
		}

		if (array_key_exists('watermark', $data)) {
			$screening->setWatermark((bool)$data['watermark']);
		}

		if (array_key_exists('openLinkEnabled', $data)) {
			$screening->setOpenLinkEnabled((bool)$data['openLinkEnabled']);
		}

		if (array_key_exists('retentionDays', $data)) {
			$days = (int)$data['retentionDays'];
			if ($days < 1 || $days > self::MAX_RETENTION_DAYS) {
				throw new ValidationException('Retention must be between 1 and 3650 days', 'retentionDays');
			}
			$screening->setRetentionDays($days);
		}
	}

	/**
	 * @throws ValidationException
	 */
	private function resolveSource(string $path, string $uid): File {
		try {
			$node = $this->rootFolder->getUserFolder($uid)->get($path);
		} catch (FileNotFoundException) {
			throw new ValidationException('The selected file does not exist', 'sourcePath');
		}
		if (!$node instanceof File) {
			throw new ValidationException('Select a file, not a folder', 'sourcePath');
		}
		return $node;
	}

	/**
	 * @throws ValidationException
	 */
	private function validateSourceForMode(Screening $screening, string $uid): void {
		$file = $this->sourceFile($screening);
		if ($file === null) {
			return;
		}
		$isPlaylist = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)) === 'm3u8';
		$isVideo = str_starts_with($file->getMimetype(), 'video/');

		if ($screening->getDeliveryMode() === Screening::MODE_HLS && !$isPlaylist) {
			throw new ValidationException('Pre-packaged HLS needs the .m3u8 playlist file', 'sourcePath');
		}
		if ($screening->getDeliveryMode() !== Screening::MODE_HLS && !$isVideo) {
			throw new ValidationException('Select a video file', 'sourcePath');
		}
	}

	private function resetMedia(Screening $screening): void {
		$screening->setMediaProgress(0);
		$screening->setMediaError(null);
		$screening->setDurationMs(null);
		if ($screening->getSourceFileId() === null) {
			$screening->setMediaStatus(Screening::MEDIA_NONE);
		} elseif ($screening->getDeliveryMode() === Screening::MODE_MP4) {
			// Direct MP4 needs no processing. Faststart checks arrive in phase 3.
			$screening->setMediaStatus(Screening::MEDIA_READY);
		} else {
			// Picked up by the media background job (phase 3).
			$screening->setMediaStatus(Screening::MEDIA_PENDING);
		}
	}

	private function sourceFile(Screening $screening): ?File {
		$fileId = $screening->getSourceFileId();
		if ($fileId === null) {
			return null;
		}
		$node = $this->rootFolder->getUserFolder($screening->getOwnerUid())->getFirstNodeById($fileId);
		return $node instanceof File ? $node : null;
	}

	private function sourcePath(Screening $screening): ?string {
		$file = $this->sourceFile($screening);
		if ($file === null) {
			return null;
		}
		return $this->rootFolder->getUserFolder($screening->getOwnerUid())->getRelativePath($file->getPath());
	}
}

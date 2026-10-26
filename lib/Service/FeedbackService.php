<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Db\Note;
use OCA\Screenings\Db\NoteMapper;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Stream\StreamContext;
use OCA\Screenings\Stream\StreamDenied;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Notes viewers leave at a moment in the film.
 *
 * Only invited viewers whose invite has notes turned on can write them, and
 * only their own. Notes are what a viewer chose to give the owner, so the
 * tracking retention period doesn't apply; they go with the invite.
 */
class FeedbackService {
	public const NOTE_ADDED = 'note_added';
	public const MAX_NOTE_CHARS = 2000;
	public const MAX_NOTES_PER_HOUR = 60;

	private ICache $cache;

	public function __construct(
		private NoteMapper $notes,
		private EventLogger $events,
		private ITimeFactory $time,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('screenings-feedback');
	}

	/**
	 * @return Note[]
	 * @throws StreamDenied
	 */
	public function viewerNotes(StreamContext $ctx): array {
		$this->requireNotes($ctx);
		return $this->notes->findForInvite($ctx->invite->getId());
	}

	/**
	 * @throws StreamDenied|ValidationException
	 */
	public function addNote(StreamContext $ctx, mixed $position, mixed $body): Note {
		$this->requireNotes($ctx);
		$text = self::cleanBody($body);
		if (!$this->withinRateLimit($ctx->invite->getId())) {
			throw new ValidationException('You have left a lot of notes in a short time. Try again later.');
		}
		$positionS = self::clampPosition($position, ($ctx->screening->getDurationMs() ?? 0) / 1000);
		$now = $this->time->getTime();

		$note = new Note();
		$note->setScreeningId($ctx->screening->getId());
		$note->setInviteId($ctx->invite->getId());
		$note->setSessionId($ctx->session->getId());
		$note->setPositionS($positionS);
		$note->setBody($text);
		$note->setCreatedAt($now);
		$note->setUpdatedAt($now);
		$note = $this->notes->insert($note);

		// The activity timeline shows that a note was left; the text itself
		// stays in the note, so editing or deleting it leaves no copy behind.
		$this->events->log($ctx->screening->getId(), $ctx->invite->getId(), $ctx->session->getId(), self::NOTE_ADDED, ['note' => $note->getId()], $positionS);
		return $note;
	}

	/**
	 * @throws StreamDenied|ValidationException|NotFoundException
	 */
	public function updateNote(StreamContext $ctx, int $noteId, mixed $body): Note {
		$note = $this->ownNote($ctx, $noteId);
		$note->setBody(self::cleanBody($body));
		$note->setUpdatedAt($this->time->getTime());
		return $this->notes->update($note);
	}

	/**
	 * @throws StreamDenied|NotFoundException
	 */
	public function deleteNote(StreamContext $ctx, int $noteId): void {
		$this->notes->delete($this->ownNote($ctx, $noteId));
	}

	/**
	 * @throws ValidationException
	 */
	public static function cleanBody(mixed $body): string {
		$text = is_string($body) ? trim(str_replace(["\r\n", "\r"], "\n", $body)) : '';
		if ($text === '') {
			throw new ValidationException('Write something first', 'body');
		}
		if (mb_strlen($text) > self::MAX_NOTE_CHARS) {
			throw new ValidationException('The note is too long', 'body');
		}
		return $text;
	}

	/**
	 * A point in the film, never before the start or past the end.
	 *
	 * @throws ValidationException
	 */
	public static function clampPosition(mixed $position, float $durationS): float {
		if (!is_numeric($position) || !is_finite((float)$position)) {
			throw new ValidationException('Unknown position in the film', 'position');
		}
		$seconds = max(0.0, (float)$position);
		return round($durationS > 0 ? min($seconds, $durationS) : $seconds, 2);
	}

	/**
	 * @throws StreamDenied
	 */
	private function requireNotes(StreamContext $ctx): void {
		if (!$ctx->invite->mayLeaveNotes()) {
			throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
		}
	}

	/**
	 * @throws StreamDenied|NotFoundException
	 */
	private function ownNote(StreamContext $ctx, int $noteId): Note {
		$this->requireNotes($ctx);
		try {
			$note = $this->notes->find($noteId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Note not found');
		}
		if ($note->getInviteId() !== $ctx->invite->getId()) {
			throw new NotFoundException('Note not found');
		}
		return $note;
	}

	private function withinRateLimit(int $inviteId): bool {
		$key = 'notes-' . $inviteId . '-' . intdiv($this->time->getTime(), 3600);
		$used = (int)($this->cache->get($key) ?? 0);
		if ($used >= self::MAX_NOTES_PER_HOUR) {
			return false;
		}
		$this->cache->set($key, $used + 1, 3600);
		return true;
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\InviteMapper;
use OCA\Screenings\Db\Note;
use OCA\Screenings\Db\NoteMapper;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Notification\Notifier;
use OCA\Screenings\Stream\StreamContext;
use OCA\Screenings\Stream\StreamDenied;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

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
	public const SRT_CUE_SECONDS = 4;

	private ICache $cache;

	public function __construct(
		private NoteMapper $notes,
		private InviteMapper $invites,
		private INotificationManager $notifications,
		private EventLogger $events,
		private ITimeFactory $time,
		private LoggerInterface $logger,
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
		try {
			$this->notifyOwner($ctx);
		} catch (\Throwable $e) {
			// The note is saved; a missing notification must not tell the viewer otherwise.
			$this->logger->error('Could not notify the owner about a note', ['exception' => $e]);
		}
		return $note;
	}

	/**
	 * One notification per viewer session, replaced with the new count, so
	 * a viewer writing ten notes doesn't produce ten notifications.
	 */
	private function notifyOwner(StreamContext $ctx): void {
		$sessionId = (string)$ctx->session->getId();
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($ctx->screening->getOwnerUid())
			->setObject(Notifier::OBJECT_SESSION, $sessionId);
		$this->notifications->markProcessed($notification);

		$notification->setDateTime(new \DateTime('@' . $this->time->getTime()))
			->setSubject(Notifier::SUBJECT_NOTES, [
				'viewer' => $ctx->invite->getName(),
				'title' => $ctx->screening->getTitle(),
				'screeningId' => $ctx->screening->getId(),
				'count' => $this->notes->countForSession($ctx->session->getId()),
			]);
		$this->notifications->notify($notification);
	}

	/**
	 * Every note of a screening with its viewer, for the owner's review page.
	 * Viewers get a stable colour index in order of their first note.
	 *
	 * @return array{notes: list<array>, viewers: list<array>}
	 */
	public function ownerNotes(Screening $screening): array {
		$invites = [];
		foreach ($this->invites->findForScreening($screening->getId()) as $invite) {
			$invites[$invite->getId()] = $invite;
		}
		$notes = $this->notes->findForScreening($screening->getId());

		$viewers = [];
		foreach ($notes as $note) {
			$inviteId = $note->getInviteId();
			if (!isset($viewers[$inviteId])) {
				$invite = $invites[$inviteId] ?? null;
				$viewers[$inviteId] = [
					'inviteId' => $inviteId,
					'name' => $invite?->getName() ?? '?',
					'email' => $invite?->getEmail() ?? '',
					'colour' => count($viewers),
					'count' => 0,
				];
			}
			$viewers[$inviteId]['count']++;
		}

		return [
			'notes' => array_map(fn (Note $n) => $n->jsonSerialize() + ['inviteId' => $n->getInviteId()], $notes),
			'viewers' => array_values($viewers),
		];
	}

	/**
	 * @throws NotFoundException
	 */
	public function getNote(int $noteId): Note {
		try {
			return $this->notes->find($noteId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Note not found');
		}
	}

	public function deleteOwnerNote(Note $note): void {
		$this->notes->delete($note);
	}

	/**
	 * @param array{notes: list<array>, viewers: list<array>} $data from ownerNotes()
	 */
	public static function csv(array $data): string {
		$names = array_column($data['viewers'], null, 'inviteId');
		$out = fopen('php://temp', 'r+');
		fputcsv($out, ['time', 'seconds', 'viewer', 'email', 'note', 'written'], ',', '"', '');
		foreach ($data['notes'] as $note) {
			$viewer = $names[$note['inviteId']] ?? ['name' => '?', 'email' => ''];
			fputcsv($out, [
				self::timecode($note['position'], false),
				$note['position'],
				self::csvSafe($viewer['name']),
				self::csvSafe($viewer['email']),
				self::csvSafe($note['body']),
				gmdate('Y-m-d H:i', (int)$note['createdAt']),
			], ',', '"', '');
		}
		rewind($out);
		$csv = (string)stream_get_contents($out);
		fclose($out);
		// A BOM so spreadsheet apps read the names as UTF-8.
		return "\u{FEFF}" . $csv;
	}

	/**
	 * One cue per note, "Name: note", to load into an editing timeline.
	 *
	 * @param array{notes: list<array>, viewers: list<array>} $data from ownerNotes()
	 */
	public static function srt(array $data): string {
		$names = array_column($data['viewers'], 'name', 'inviteId');
		$cues = [];
		foreach ($data['notes'] as $i => $note) {
			$start = (float)$note['position'];
			$cues[] = ($i + 1) . "\n"
				. self::timecode($start, true) . ' --> ' . self::timecode($start + self::SRT_CUE_SECONDS, true) . "\n"
				. ($names[$note['inviteId']] ?? '?') . ': ' . str_replace("\n\n", "\n", $note['body']) . "\n";
		}
		return implode("\n", $cues);
	}

	/**
	 * 1:02:03 (or 01:02:03,450 for SRT).
	 */
	public static function timecode(float $seconds, bool $srt): string {
		$ms = (int)round(max(0.0, $seconds) * 1000);
		$h = intdiv($ms, 3600000);
		$m = intdiv($ms % 3600000, 60000);
		$s = intdiv($ms % 60000, 1000);
		if ($srt) {
			return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $ms % 1000);
		}
		return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
	}

	/**
	 * Spreadsheet apps run cells starting with = + - @ as formulas; a
	 * viewer's note must not.
	 */
	private static function csvSafe(string $value): string {
		return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
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

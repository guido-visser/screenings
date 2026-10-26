<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\Answer;
use OCA\Screenings\Db\AnswerMapper;
use OCA\Screenings\Db\InviteMapper;
use OCA\Screenings\Db\Question;
use OCA\Screenings\Db\QuestionMapper;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Notification\Notifier;
use OCA\Screenings\Stream\StreamContext;
use OCA\Screenings\Stream\StreamDenied;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Closing questions: the owner sets them per screening, invited viewers
 * whose invite asks for them answer (and may change their answers).
 */
class QuestionService {
	public const QUESTIONS_ANSWERED = 'questions_answered';
	public const MAX_QUESTIONS = 10;
	public const MAX_OPTIONS = 10;
	public const MAX_OPTION_CHARS = 100;
	public const MAX_ANSWER_CHARS = 2000;

	public function __construct(
		private QuestionMapper $questions,
		private AnswerMapper $answers,
		private InviteMapper $invites,
		private EventLogger $events,
		private INotificationManager $notifications,
		private IDBConnection $db,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return Question[]
	 */
	public function listForScreening(int $screeningId): array {
		return $this->questions->findForScreening($screeningId);
	}

	/**
	 * Replaces the screening's questions with the owner's list. Questions
	 * keep their id (and answers) while they exist; a removed question, or
	 * one that changed kind, loses its answers.
	 *
	 * @param mixed $items list of {id?, kind, label, options?, required?}
	 * @return Question[]
	 * @throws ValidationException
	 */
	public function save(int $screeningId, mixed $items): array {
		if (!is_array($items) || !array_is_list($items)) {
			throw new ValidationException('Invalid list of questions');
		}
		if (count($items) > self::MAX_QUESTIONS) {
			throw new ValidationException('At most ' . self::MAX_QUESTIONS . ' questions');
		}
		$clean = array_map(self::normalize(...), $items);

		$existing = [];
		foreach ($this->questions->findForScreening($screeningId) as $question) {
			$existing[$question->getId()] = $question;
		}

		$this->db->beginTransaction();
		try {
			$kept = [];
			foreach ($clean as $sort => $item) {
				$question = $item['id'] !== null ? ($existing[$item['id']] ?? null) : null;
				if ($question === null) {
					$question = new Question();
					$question->setScreeningId($screeningId);
				} elseif ($question->getKind() !== $item['kind']) {
					$this->answers->deleteForQuestions([$question->getId()]);
				}
				$question->setSort($sort);
				$question->setKind($item['kind']);
				$question->setLabel($item['label']);
				$question->setOptions($item['kind'] === Question::KIND_CHOICE ? json_encode($item['options']) : null);
				$question->setRequired($item['required']);
				$question = $question->getId() === null ? $this->questions->insert($question) : $this->questions->update($question);
				$kept[$question->getId()] = true;
			}
			$removed = array_values(array_diff(array_keys($existing), array_keys($kept)));
			$this->answers->deleteForQuestions($removed);
			foreach ($removed as $id) {
				$this->questions->delete($existing[$id]);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->questions->findForScreening($screeningId);
	}

	/**
	 * @return array{id: ?int, kind: string, label: string, options: list<string>, required: bool}
	 * @throws ValidationException
	 */
	public static function normalize(mixed $item): array {
		if (!is_array($item)) {
			throw new ValidationException('Invalid question');
		}
		$kind = $item['kind'] ?? null;
		if (!in_array($kind, Question::KINDS, true)) {
			throw new ValidationException('Unknown kind of question');
		}
		$label = is_string($item['label'] ?? null) ? trim($item['label']) : '';
		if ($label === '') {
			throw new ValidationException('Every question needs a text', 'label');
		}
		if (mb_strlen($label) > 255) {
			throw new ValidationException('A question can be at most 255 characters', 'label');
		}
		$options = [];
		if ($kind === Question::KIND_CHOICE) {
			foreach (is_array($item['options'] ?? null) ? $item['options'] : [] as $option) {
				$option = is_string($option) ? trim($option) : '';
				if ($option !== '' && !in_array($option, $options, true)) {
					if (mb_strlen($option) > self::MAX_OPTION_CHARS) {
						throw new ValidationException('An option can be at most ' . self::MAX_OPTION_CHARS . ' characters', 'options');
					}
					$options[] = $option;
				}
			}
			if (count($options) < 2) {
				throw new ValidationException('A choice needs at least two options', 'options');
			}
			if (count($options) > self::MAX_OPTIONS) {
				throw new ValidationException('At most ' . self::MAX_OPTIONS . ' options', 'options');
			}
		}
		$id = $item['id'] ?? null;
		return [
			'id' => is_int($id) && $id > 0 ? $id : null,
			'kind' => $kind,
			'label' => $label,
			'options' => $options,
			'required' => !empty($item['required']),
		];
	}

	/**
	 * A viewer's answer as stored text, or null for "not answered".
	 *
	 * @throws ValidationException
	 */
	public static function cleanAnswer(Question $question, mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		switch ($question->getKind()) {
			case Question::KIND_RATING:
				if (!is_numeric($value) || (int)$value != $value || (int)$value < 1 || (int)$value > 5) {
					throw new ValidationException('Choose 1 to 5 stars', 'q' . $question->getId());
				}
				return (string)(int)$value;
			case Question::KIND_CHOICE:
				if (!is_string($value) || !in_array($value, $question->optionList(), true)) {
					throw new ValidationException('Choose one of the options', 'q' . $question->getId());
				}
				return $value;
			default:
				$text = is_string($value) ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : '';
				if (mb_strlen($text) > self::MAX_ANSWER_CHARS) {
					throw new ValidationException('The answer is too long', 'q' . $question->getId());
				}
				return $text === '' ? null : $text;
		}
	}

	/**
	 * What the viewer page gets: null when this invite isn't asked or the
	 * screening has no questions.
	 *
	 * @return ?array{count: int, answered: bool, pending: bool}
	 */
	public function viewerState(StreamContext $ctx): ?array {
		if (!$ctx->invite->mayAnswerQuestions()) {
			return null;
		}
		$count = count($this->questions->findForScreening($ctx->screening->getId()));
		if ($count === 0) {
			return null;
		}
		$answered = $this->answers->findForInvite($ctx->invite->getId()) !== [];
		// Finished the film earlier but never answered: the page asks once.
		return ['count' => $count, 'answered' => $answered, 'pending' => !$answered && $this->hasEnded($ctx->invite->getId())];
	}

	/**
	 * @return array{questions: Question[], answers: array<int, string>}
	 * @throws StreamDenied
	 */
	public function viewerQuestions(StreamContext $ctx): array {
		$this->requireQuestions($ctx);
		$answers = [];
		foreach ($this->answers->findForInvite($ctx->invite->getId()) as $answer) {
			$answers[$answer->getQuestionId()] = $answer->getValue();
		}
		return [
			'questions' => $this->questions->findForScreening($ctx->screening->getId()),
			'answers' => (object)$answers,
		];
	}

	/**
	 * Saves all answers at once (the form is sent as a whole).
	 *
	 * @param mixed $values {questionId: value}
	 * @throws StreamDenied|ValidationException
	 */
	public function saveAnswers(StreamContext $ctx, mixed $values): array {
		$this->requireQuestions($ctx);
		$values = is_array($values) ? $values : [];
		$questions = $this->questions->findForScreening($ctx->screening->getId());

		$clean = [];
		foreach ($questions as $question) {
			$value = self::cleanAnswer($question, $values[$question->getId()] ?? $values[(string)$question->getId()] ?? null);
			if ($value === null && $question->getRequired()) {
				throw new ValidationException('Please answer this question', 'q' . $question->getId());
			}
			$clean[$question->getId()] = $value;
		}

		$existing = [];
		foreach ($this->answers->findForInvite($ctx->invite->getId()) as $answer) {
			$existing[$answer->getQuestionId()] = $answer;
		}
		$now = $this->time->getTime();
		$this->db->beginTransaction();
		try {
			foreach ($clean as $questionId => $value) {
				$answer = $existing[$questionId] ?? null;
				if ($value === null) {
					if ($answer !== null) {
						$this->answers->delete($answer);
					}
					continue;
				}
				if ($answer === null) {
					$answer = new Answer();
					$answer->setScreeningId($ctx->screening->getId());
					$answer->setInviteId($ctx->invite->getId());
					$answer->setQuestionId($questionId);
				} elseif ($answer->getValue() === $value) {
					continue;
				}
				$answer->setValue($value);
				$answer->setAnsweredAt($now);
				$answer->getId() === null ? $this->answers->insert($answer) : $this->answers->update($answer);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->events->log($ctx->screening->getId(), $ctx->invite->getId(), $ctx->session->getId(), self::QUESTIONS_ANSWERED, ['answered' => count(array_filter($clean, fn ($v) => $v !== null))]);
		try {
			$this->notifyOwner($ctx);
		} catch (\Throwable $e) {
			$this->logger->error('Could not notify the owner about answers', ['exception' => $e]);
		}
		return $this->viewerQuestions($ctx);
	}

	/**
	 * Everything for the owner's Answers view: per question a summary, per
	 * viewer their answers.
	 */
	public function ownerAnswers(Screening $screening): array {
		$questions = $this->questions->findForScreening($screening->getId());
		$answers = $this->answers->findForScreening($screening->getId());
		$invites = [];
		foreach ($this->invites->findForScreening($screening->getId()) as $invite) {
			$invites[$invite->getId()] = $invite;
		}

		$viewers = [];
		$byQuestion = [];
		foreach ($answers as $answer) {
			$inviteId = $answer->getInviteId();
			$viewers[$inviteId] ??= [
				'inviteId' => $inviteId,
				'name' => isset($invites[$inviteId]) ? $invites[$inviteId]->getName() : '?',
				'email' => isset($invites[$inviteId]) ? $invites[$inviteId]->getEmail() : '',
				'answeredAt' => 0,
				'answers' => [],
			];
			$viewers[$inviteId]['answers'][$answer->getQuestionId()] = $answer->getValue();
			$viewers[$inviteId]['answeredAt'] = max($viewers[$inviteId]['answeredAt'], $answer->getAnsweredAt());
			$byQuestion[$answer->getQuestionId()][] = $answer->getValue();
		}
		foreach ($viewers as &$viewer) {
			$viewer['answers'] = (object)$viewer['answers'];
		}
		unset($viewer);

		$asked = count(array_filter($invites, fn ($i) => $i->mayAnswerQuestions()));
		return [
			'asked' => $asked,
			'questions' => array_map(fn (Question $q) => $q->jsonSerialize() + ['summary' => self::summarize($q, $byQuestion[$q->getId()] ?? [])], $questions),
			'viewers' => array_values($viewers),
		];
	}

	/**
	 * @param list<string> $values
	 */
	public static function summarize(Question $question, array $values): array {
		$summary = ['count' => count($values)];
		if ($question->getKind() === Question::KIND_RATING) {
			$distribution = array_fill(1, 5, 0);
			foreach ($values as $value) {
				$distribution[(int)$value] = ($distribution[(int)$value] ?? 0) + 1;
			}
			$summary['average'] = $values === [] ? null : round(array_sum(array_map('intval', $values)) / count($values), 2);
			$summary['distribution'] = array_values($distribution);
		} elseif ($question->getKind() === Question::KIND_CHOICE) {
			$counts = array_fill_keys($question->optionList(), 0);
			$other = 0;
			foreach ($values as $value) {
				if (array_key_exists($value, $counts)) {
					$counts[$value]++;
				} else {
					// An option the owner has since removed.
					$other++;
				}
			}
			$summary['options'] = array_map(fn ($option, $count) => ['option' => (string)$option, 'count' => $count], array_keys($counts), array_values($counts));
			$summary['other'] = $other;
		}
		return $summary;
	}

	/**
	 * One row per viewer, one column per question.
	 */
	public static function csv(array $data): string {
		$out = fopen('php://temp', 'r+');
		$header = ['viewer', 'email', 'answered'];
		foreach ($data['questions'] as $question) {
			$header[] = self::csvSafe($question['label']);
		}
		fputcsv($out, $header, ',', '"', '');
		foreach ($data['viewers'] as $viewer) {
			$answers = (array)$viewer['answers'];
			$row = [self::csvSafe($viewer['name']), self::csvSafe($viewer['email']), gmdate('Y-m-d H:i', (int)$viewer['answeredAt'])];
			foreach ($data['questions'] as $question) {
				$row[] = self::csvSafe((string)($answers[$question['id']] ?? ''));
			}
			fputcsv($out, $row, ',', '"', '');
		}
		rewind($out);
		$csv = (string)stream_get_contents($out);
		fclose($out);
		return "\u{FEFF}" . $csv;
	}

	private static function csvSafe(string $value): string {
		return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
	}

	/**
	 * @throws StreamDenied
	 */
	private function requireQuestions(StreamContext $ctx): void {
		if (!$ctx->invite->mayAnswerQuestions()) {
			throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
		}
	}

	private function hasEnded(int $inviteId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('scr_events')
			->where($qb->expr()->eq('invite_id', $qb->createNamedParameter($inviteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('ended')))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		return $found;
	}

	/**
	 * One notification per invite, replaced when they change their answers.
	 */
	private function notifyOwner(StreamContext $ctx): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($ctx->screening->getOwnerUid())
			->setObject(Notifier::OBJECT_ANSWERS, (string)$ctx->invite->getId());
		$this->notifications->markProcessed($notification);
		$notification->setDateTime(new \DateTime('@' . $this->time->getTime()))
			->setSubject(Notifier::SUBJECT_ANSWERS, [
				'viewer' => $ctx->invite->getName(),
				'title' => $ctx->screening->getTitle(),
				'screeningId' => $ctx->screening->getId(),
			]);
		$this->notifications->notify($notification);
	}
}

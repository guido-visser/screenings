<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Service;

use OCA\Screenings\Db\Question;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Service\QuestionService;
use PHPUnit\Framework\TestCase;

class QuestionServiceTest extends TestCase {
	private static function question(string $kind, array $options = [], int $id = 7): Question {
		$question = new Question();
		$question->setId($id);
		$question->setKind($kind);
		$question->setLabel('Q');
		$question->setOptions($options === [] ? null : json_encode($options));
		return $question;
	}

	public function testNormalizeCleansAQuestion(): void {
		$this->assertSame(
			['id' => 3, 'kind' => 'choice', 'label' => 'Was the ending clear?', 'options' => ['Yes', 'No'], 'required' => true],
			QuestionService::normalize(['id' => 3, 'kind' => 'choice', 'label' => '  Was the ending clear? ', 'options' => [' Yes', 'No', 'Yes', '', 5], 'required' => 1]),
		);
		$this->assertSame(
			['id' => null, 'kind' => 'rating', 'label' => 'Overall?', 'options' => [], 'required' => false],
			QuestionService::normalize(['id' => 'x', 'kind' => 'rating', 'label' => 'Overall?', 'options' => ['ignored', 'too']]),
		);
	}

	public function testNormalizeRefusesBadQuestions(): void {
		$bad = [
			'not an array' => 'x',
			'unknown kind' => ['kind' => 'poll', 'label' => 'x'],
			'no text' => ['kind' => 'text', 'label' => '  '],
			'too long' => ['kind' => 'text', 'label' => str_repeat('a', 256)],
			'one option' => ['kind' => 'choice', 'label' => 'x', 'options' => ['a', 'a']],
			'too many options' => ['kind' => 'choice', 'label' => 'x', 'options' => range(1, 11)],
			'long option' => ['kind' => 'choice', 'label' => 'x', 'options' => ['a', str_repeat('b', 101)]],
		];
		foreach ($bad as $case => $item) {
			try {
				QuestionService::normalize($item);
				$this->fail("accepted: $case");
			} catch (ValidationException) {
				$this->addToAssertionCount(1);
			}
		}
		// Eleven numbers as strings would be valid options, but too many.
		$this->expectException(ValidationException::class);
		QuestionService::normalize(['kind' => 'choice', 'label' => 'x', 'options' => array_map('strval', range(1, 11))]);
	}

	public function testRatingAnswers(): void {
		$rating = self::question(Question::KIND_RATING);
		$this->assertSame('4', QuestionService::cleanAnswer($rating, 4));
		$this->assertSame('5', QuestionService::cleanAnswer($rating, '5'));
		$this->assertNull(QuestionService::cleanAnswer($rating, ''));
		foreach ([0, 6, '3.5', 'abc', true] as $value) {
			try {
				QuestionService::cleanAnswer($rating, $value);
				$this->fail('accepted ' . var_export($value, true));
			} catch (ValidationException $e) {
				$this->assertSame('q7', $e->getField());
			}
		}
	}

	public function testChoiceAndTextAnswers(): void {
		$choice = self::question(Question::KIND_CHOICE, ['Yes', 'No']);
		$this->assertSame('No', QuestionService::cleanAnswer($choice, 'No'));
		try {
			QuestionService::cleanAnswer($choice, 'Maybe');
			$this->fail('accepted an unknown option');
		} catch (ValidationException) {
			$this->addToAssertionCount(1);
		}

		$text = self::question(Question::KIND_TEXT);
		$this->assertSame("a\nb", QuestionService::cleanAnswer($text, " a\r\nb  "));
		$this->assertNull(QuestionService::cleanAnswer($text, "  \n "));
		$this->expectException(ValidationException::class);
		QuestionService::cleanAnswer($text, str_repeat('x', QuestionService::MAX_ANSWER_CHARS + 1));
	}

	public function testSummaries(): void {
		$this->assertSame(
			['count' => 4, 'average' => 3.75, 'distribution' => [0, 0, 2, 1, 1]],
			QuestionService::summarize(self::question(Question::KIND_RATING), ['3', '5', '3', '4']),
		);
		$this->assertSame(
			['count' => 0, 'average' => null, 'distribution' => [0, 0, 0, 0, 0]],
			QuestionService::summarize(self::question(Question::KIND_RATING), []),
		);
		$this->assertSame(
			['count' => 3, 'options' => [['option' => 'Yes', 'count' => 2], ['option' => 'No', 'count' => 0]], 'other' => 1],
			QuestionService::summarize(self::question(Question::KIND_CHOICE, ['Yes', 'No']), ['Yes', 'Removed', 'Yes']),
		);
	}

	public function testCsvHasOneRowPerViewer(): void {
		$csv = QuestionService::csv([
			'questions' => [['id' => 1, 'label' => 'Overall?'], ['id' => 2, 'label' => '=Anything else?']],
			'viewers' => [
				['name' => 'Anna', 'email' => 'anna@example.test', 'answeredAt' => 1793050569, 'answers' => (object)[1 => '4', 2 => "+1, loved it"]],
				['name' => 'Bob', 'email' => 'bob@example.test', 'answeredAt' => 1793050600, 'answers' => (object)[1 => '2']],
			],
		]);
		$lines = explode("\n", trim($csv));
		$this->assertSame("\u{FEFF}viewer,email,answered,Overall?,\"'=Anything else?\"", $lines[0]);
		$this->assertSame('Anna,anna@example.test,"2026-10-26 21:36",4,"\'+1, loved it"', $lines[1]);
		$this->assertSame('Bob,bob@example.test,"2026-10-26 21:36",2,', $lines[2]);
	}
}

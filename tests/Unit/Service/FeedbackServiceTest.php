<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Service;

use OCA\Screenings\Db\Invite;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Service\FeedbackService;
use PHPUnit\Framework\TestCase;

class FeedbackServiceTest extends TestCase {
	public function testBodyIsTrimmedWithUnixLineBreaks(): void {
		$this->assertSame("first\nsecond", FeedbackService::cleanBody("  first\r\nsecond \n"));
	}

	public function testEmptyBodyIsRefused(): void {
		$this->expectException(ValidationException::class);
		FeedbackService::cleanBody("  \n ");
	}

	public function testNonStringBodyIsRefused(): void {
		$this->expectException(ValidationException::class);
		FeedbackService::cleanBody(['x']);
	}

	public function testLongBodyIsRefused(): void {
		$this->assertSame(FeedbackService::MAX_NOTE_CHARS, mb_strlen(FeedbackService::cleanBody(str_repeat('é', FeedbackService::MAX_NOTE_CHARS))));
		$this->expectException(ValidationException::class);
		FeedbackService::cleanBody(str_repeat('é', FeedbackService::MAX_NOTE_CHARS + 1));
	}

	public function testPositionIsClampedToTheFilm(): void {
		$this->assertSame(0.0, FeedbackService::clampPosition(-3, 600));
		$this->assertSame(600.0, FeedbackService::clampPosition(9999, 600));
		$this->assertSame(12.35, FeedbackService::clampPosition('12.345', 600));
		$this->assertSame(9999.0, FeedbackService::clampPosition(9999, 0), 'unknown duration: no upper bound');
	}

	public function testPositionMustBeANumber(): void {
		foreach (['abc', null, [], 'NaN', 'INF'] as $value) {
			try {
				FeedbackService::clampPosition($value, 600);
				$this->fail('accepted ' . var_export($value, true));
			} catch (ValidationException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testTimecodes(): void {
		$this->assertSame('0:03', FeedbackService::timecode(3.4, false));
		$this->assertSame('1:02:03', FeedbackService::timecode(3723, false));
		$this->assertSame('00:00:03,450', FeedbackService::timecode(3.45, true));
		$this->assertSame('01:02:03,000', FeedbackService::timecode(3723, true));
		$this->assertSame('0:00', FeedbackService::timecode(-5, false));
	}

	private function exportData(): array {
		return [
			'notes' => [
				['id' => 1, 'inviteId' => 7, 'position' => 3.5, 'body' => "Too long here\n\nCut?", 'createdAt' => 1793050569],
				['id' => 2, 'inviteId' => 8, 'position' => 3723.0, 'body' => '=HYPERLINK("x")', 'createdAt' => 1793050600],
			],
			'viewers' => [
				['inviteId' => 7, 'name' => 'Anna, "the editor"', 'email' => 'anna@example.test', 'colour' => 0, 'count' => 1],
				['inviteId' => 8, 'name' => '@Bob', 'email' => 'bob@example.test', 'colour' => 1, 'count' => 1],
			],
		];
	}

	public function testSrtHasOneCuePerNote(): void {
		$expected = "1\n00:00:03,500 --> 00:00:07,500\nAnna, \"the editor\": Too long here\nCut?\n"
			. "\n2\n01:02:03,000 --> 01:02:07,000\n@Bob: =HYPERLINK(\"x\")\n";
		$this->assertSame($expected, FeedbackService::srt($this->exportData()));
	}

	public function testCsvQuotesAndDefusesFormulas(): void {
		$csv = FeedbackService::csv($this->exportData());
		$this->assertStringStartsWith("\u{FEFF}time,seconds,viewer,email,note,written\n", $csv);
		$this->assertStringContainsString('0:03,3.5,"Anna, ""the editor""",anna@example.test,"Too long here' . "\n\nCut?" . '",', $csv);
		$this->assertStringContainsString('1:02:03,3723,\'@Bob,bob@example.test,"\'=HYPERLINK(""x"")",', $csv);
	}

	public function testOpenLinkVisitorsNeverLeaveFeedback(): void {
		$invite = new Invite();
		$invite->setSource(Invite::SOURCE_INVITED);
		$this->assertFalse($invite->mayLeaveNotes(), 'off by default');
		$invite->setAllowNotes(true);
		$invite->setAskQuestions(true);
		$this->assertTrue($invite->mayLeaveNotes());
		$this->assertTrue($invite->mayAnswerQuestions());

		$invite->setSource(Invite::SOURCE_OPEN_LINK);
		$this->assertFalse($invite->mayLeaveNotes());
		$this->assertFalse($invite->mayAnswerQuestions());
	}
}

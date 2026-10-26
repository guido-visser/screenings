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

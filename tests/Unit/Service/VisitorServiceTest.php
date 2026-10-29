<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Service;

use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Service\AccessService;
use OCA\Screenings\Service\VisitorService;
use PHPUnit\Framework\TestCase;

class VisitorServiceTest extends TestCase {
	private const SECRET = 'test-secret';

	public function testCookieRoundTrip(): void {
		$cookie = VisitorService::sign(self::SECRET, 7, 42);
		$this->assertSame(42, VisitorService::verify(self::SECRET, 7, $cookie));
	}

	public function testCookieIsBoundToScreeningAndSecret(): void {
		$cookie = VisitorService::sign(self::SECRET, 7, 42);
		$this->assertNull(VisitorService::verify(self::SECRET, 8, $cookie), 'another screening');
		$this->assertNull(VisitorService::verify('other-secret', 7, $cookie), 'another server');
	}

	public function testForgedCookiesAreRefused(): void {
		$cookie = VisitorService::sign(self::SECRET, 7, 42);
		[, $mac] = explode('.', $cookie);
		$this->assertNull(VisitorService::verify(self::SECRET, 7, '43.' . $mac), 'someone else\'s visitor id');
		foreach (['', '42', '42.', 'x.' . $mac, '42.' . strtoupper($mac), $cookie . '0'] as $bad) {
			$this->assertNull(VisitorService::verify(self::SECRET, 7, $bad), var_export($bad, true));
		}
	}

	public function testNumbersContinueAfterTheHighest(): void {
		$this->assertSame(1, VisitorService::nextNumber([]));
		$this->assertSame(6, VisitorService::nextNumber(['Visitor 1', 'Visitor 5', 'Visitor 3']), 'gaps from deleted visitors are not reused');
		$this->assertSame(3, VisitorService::nextNumber(['Visitor 2', 'Anna', 'Visitor x']));
	}

	public function testPublicVisitorsStopWhenTheLinkIsOff(): void {
		$visitor = new Invite();
		$visitor->setSource(Invite::SOURCE_PUBLIC);
		$screening = new Screening();
		$screening->setPublicLinkEnabled(true);
		$this->assertFalse(AccessService::isRevoked($visitor, $screening));

		$screening->setPublicLinkEnabled(false);
		$this->assertTrue(AccessService::isRevoked($visitor, $screening), 'link switched off');

		$invited = new Invite();
		$invited->setSource(Invite::SOURCE_INVITED);
		$this->assertFalse(AccessService::isRevoked($invited, $screening), 'personal invites are not affected');

		$screening->setPublicLinkEnabled(true);
		$visitor->setRevokedAt(1);
		$this->assertTrue(AccessService::isRevoked($visitor, $screening), 'blocked one by one');
	}

	public function testPublicVisitorsGiveNoFeedback(): void {
		$visitor = new Invite();
		$visitor->setSource(Invite::SOURCE_PUBLIC);
		$visitor->setAllowNotes(true);
		$visitor->setAskQuestions(true);
		$this->assertFalse($visitor->mayLeaveNotes());
		$this->assertFalse($visitor->mayAnswerQuestions());
	}
}

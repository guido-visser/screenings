<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Stream;

use OCA\Screenings\Stream\PlaybackBudget;
use OCA\Screenings\Stream\PlaybackToken;
use OCA\Screenings\Stream\StreamDenied;
use OCA\Screenings\Stream\StreamService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

class StreamSecurityTest extends TestCase {
	private const SECRET = 'instance-secret';
	private const IP = '203.0.113.7';
	private const UA = 'Mozilla/5.0 Test';

	public function testPlaybackTokenVerifiesForSameSessionIpAndAgent(): void {
		$token = PlaybackToken::create(self::SECRET, 12, self::IP, self::UA, 1060);

		$this->assertTrue(PlaybackToken::verify(self::SECRET, $token, 12, self::IP, self::UA, 1000));
	}

	public function testPlaybackTokenIsBoundToEverything(): void {
		$token = PlaybackToken::create(self::SECRET, 12, self::IP, self::UA, 1060);

		$this->assertFalse(PlaybackToken::verify(self::SECRET, $token, 12, self::IP, self::UA, 1061), 'expired');
		$this->assertFalse(PlaybackToken::verify(self::SECRET, $token, 13, self::IP, self::UA, 1000), 'other session');
		$this->assertFalse(PlaybackToken::verify(self::SECRET, $token, 12, '198.51.100.1', self::UA, 1000), 'other IP');
		$this->assertFalse(PlaybackToken::verify(self::SECRET, $token, 12, self::IP, 'ffmpeg/7.1', 1000), 'other User-Agent');
		$this->assertFalse(PlaybackToken::verify('other', $token, 12, self::IP, self::UA, 1000), 'other secret');
		$this->assertFalse(PlaybackToken::verify(self::SECRET, str_replace('.1060.', '.9999.', $token), 12, self::IP, self::UA, 1000), 'tampered expiry');
		$this->assertFalse(PlaybackToken::verify(self::SECRET, null, 12, self::IP, self::UA, 1000), 'missing');
	}

	public function testMediaSignatureIsPerResourceSessionAndAgent(): void {
		$signature = PlaybackToken::mediaSignature(self::SECRET, 12, self::UA, 'mp4');

		$this->assertTrue(PlaybackToken::verifyMediaSignature(self::SECRET, $signature, 12, self::UA, 'mp4'));
		$this->assertFalse(PlaybackToken::verifyMediaSignature(self::SECRET, $signature, 12, self::UA, 'vtt:1'));
		$this->assertFalse(PlaybackToken::verifyMediaSignature(self::SECRET, $signature, 13, self::UA, 'mp4'));
		$this->assertFalse(PlaybackToken::verifyMediaSignature(self::SECRET, $signature, 12, 'curl/8', 'mp4'));
	}

	public function testPlayheadWindow(): void {
		$this->assertTrue(PlaybackBudget::withinWindow(0, null));
		$this->assertTrue(PlaybackBudget::withinWindow(90, 0));
		$this->assertFalse(PlaybackBudget::withinWindow(91, 0));
		$this->assertTrue(PlaybackBudget::withinWindow(1290, 1200));
		// Going back is always fine.
		$this->assertTrue(PlaybackBudget::withinWindow(10, 1200));
	}

	public function testBudgetAllowsBurstThenRealTimeSpeedLimit(): void {
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(function ($key) use (&$store) {
			return $store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(function ($key, $value) use (&$store) {
			$store[$key] = $value;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$budget = new PlaybackBudget($factory);

		// A fresh session can fetch the burst (45 four-second segments)...
		for ($i = 0; $i < PlaybackBudget::BURST_SECONDS / 4; $i++) {
			$budget->consume(1, 0, 4.0);
		}
		// ...but not one more until time passes.
		try {
			$budget->consume(1, 0, 4.0);
			$this->fail('Budget should be exhausted');
		} catch (StreamDenied $e) {
			$this->assertSame(StreamDenied::AHEAD, $e->reason);
		}
		// After 10 s it may fetch 30 s more (3x real time).
		for ($i = 0; $i < 7; $i++) {
			$budget->consume(1, 10, 4.0);
		}
		$this->expectException(StreamDenied::class);
		$budget->consume(1, 10, 4.0);
	}

	public function testParseRange(): void {
		$this->assertSame([0, 999], StreamService::parseRange(null, 1000));
		$this->assertSame([0, 999], StreamService::parseRange('bytes=0-', 1000));
		$this->assertSame([100, 199], StreamService::parseRange('bytes=100-199', 1000));
		$this->assertSame([900, 999], StreamService::parseRange('bytes=-100', 1000));
		$this->assertSame([500, 999], StreamService::parseRange('bytes=500-5000', 1000));
	}

	public function testRejectsBadRanges(): void {
		foreach (['bytes=1000-', 'bytes=5-1', 'bytes=0-1,5-6', 'items=0-1', 'bytes=-'] as $range) {
			try {
				StreamService::parseRange($range, 1000);
				$this->fail($range . ' should be rejected');
			} catch (StreamDenied $e) {
				$this->assertSame(416, $e->status, $range);
			}
		}
	}
}

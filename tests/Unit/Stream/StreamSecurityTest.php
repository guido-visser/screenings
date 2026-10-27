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
		$budget = $this->budget();

		// A fresh invite can fetch the burst (45 four-second segments)...
		for ($i = 0; $i < PlaybackBudget::BURST_SECONDS / 4; $i++) {
			$budget->consume(1, 1000, 4.0);
		}
		// ...but not one more until time passes.
		try {
			$budget->consume(1, 1000, 4.0);
			$this->fail('Budget should be exhausted');
		} catch (StreamDenied $e) {
			$this->assertSame(StreamDenied::AHEAD, $e->reason);
		}
		// After 10 s it may fetch 30 s more (3x real time).
		for ($i = 0; $i < 7; $i++) {
			$budget->consume(1, 1010, 4.0);
		}
		$this->expectException(StreamDenied::class);
		$budget->consume(1, 1010, 4.0);
	}

	public function testBudgetBelongsToTheInviteNotTheSession(): void {
		$budget = $this->budget();
		for ($i = 0; $i < PlaybackBudget::BURST_SECONDS / 4; $i++) {
			$budget->consume(1, 1000, 4.0);
		}
		// Another invite has its own budget.
		$budget->consume(2, 1000, 4.0);

		// Reloading the page (a new session) a second later gives no new burst.
		$this->expectException(StreamDenied::class);
		$budget->consume(1, 1001, 4.0);
	}

	public function testBudgetDoesNotSaveUpWhileIdle(): void {
		$budget = $this->budget();
		$budget->consume(1, 1000, 4.0);

		// An hour later the bucket is full again, but holds no more than the burst.
		$this->assertSame((float)PlaybackBudget::BURST_SECONDS, PlaybackBudget::refilled(0.0, 3600));
		for ($i = 0; $i < PlaybackBudget::BURST_SECONDS / 4; $i++) {
			$budget->consume(1, 4600, 4.0);
		}
		$this->expectException(StreamDenied::class);
		$budget->consume(1, 4600, 4.0);
	}

	private function budget(): PlaybackBudget {
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
		return new PlaybackBudget($factory);
	}

	public function testMp4ChunksStayWithinTheBudgetAtLowBitrates(): void {
		// A normal screener (8 Mbit/s): the plain 2 MB cap.
		$this->assertSame(StreamService::MP4_CHUNK_BYTES, StreamService::mp4ChunkBytes(1_000_000));
		// 160 kbit/s: 20 s of film (400 KB), not 105 s.
		$this->assertSame(400_000, StreamService::mp4ChunkBytes(20_000));
		// Near-silent bitrates: the floor.
		$this->assertSame(StreamService::MP4_MIN_CHUNK_BYTES, StreamService::mp4ChunkBytes(100));
		// Three chunks always fit the burst budget from ~9 kbit/s up (below
		// that the floor takes over; no real film is that small).
		foreach ([1_100, 2_000, 20_000, 100_000, 1_000_000] as $bytesPerSecond) {
			$seconds = StreamService::mp4ChunkBytes($bytesPerSecond) / $bytesPerSecond;
			$this->assertLessThanOrEqual(PlaybackBudget::BURST_SECONDS / 3, $seconds, "at $bytesPerSecond B/s");
		}
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

	public function testIndexReadAtTheEndOfTheFile(): void {
		// The case seen in Chrome: moov at 50225942 (384809 bytes), read from
		// the 64 KB boundary before it.
		$moov = 50225942;
		$size = 384809;
		$this->assertSame($moov + $size - 1, StreamService::indexReadEnd(50200576, 50200576 + StreamService::MP4_CHUNK_BYTES - 1, $moov, $size), 'aligned read just before the index');
		$this->assertSame($moov + 999, StreamService::indexReadEnd($moov, $moov + 999, $moov, $size), 'inside the index, short range kept');
		$this->assertSame($moov + $size - 1, StreamService::indexReadEnd($moov - StreamService::INDEX_MARGIN_BYTES, $moov + 5_000_000, $moov, $size), 'cut off where the index ends');
	}

	public function testFilmDataIsNotAnIndexRead(): void {
		$moov = 50225942;
		$size = 384809;
		$this->assertNull(StreamService::indexReadEnd($moov - StreamService::INDEX_MARGIN_BYTES - 1, $moov + 10, $moov, $size), 'starts too far before the index');
		$this->assertNull(StreamService::indexReadEnd($moov - 1000, $moov - 1, $moov, $size), 'ends before the index');
		$this->assertNull(StreamService::indexReadEnd($moov + $size, $moov + $size + 10, $moov, $size), 'after the index');
		$this->assertNull(StreamService::indexReadEnd(1000, 2000, $moov, 0), 'no index known');
	}
}

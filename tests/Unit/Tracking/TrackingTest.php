<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Tracking;

use OCA\Screenings\Tracking\UserAgentParser;
use OCA\Screenings\Tracking\WatchRanges;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrackingTest extends TestCase {
	public function testMergeJoinsOverlappingAndNearbyRanges(): void {
		$this->assertSame(
			[[0.0, 30.0], [100.0, 130.0]],
			WatchRanges::merge([[0.0, 10.0], [100.0, 120.0]], [[9.5, 20.0], [20.8, 30.0], [110.0, 130.0]]),
		);
	}

	public function testMergeKeepsSeparateRanges(): void {
		$this->assertSame([[0.0, 5.0], [10.0, 15.0]], WatchRanges::merge([[10.0, 15.0]], [[0.0, 5.0]]));
	}

	public function testMergeOfContainedRange(): void {
		$this->assertSame([[0.0, 60.0]], WatchRanges::merge([[0.0, 60.0]], [[10.0, 20.0]]));
	}

	public function testSanitizeDropsJunkAndClampsToDuration(): void {
		$this->assertSame(
			[[0.0, 4.0], [95.0, 100.0]],
			WatchRanges::sanitize([[-5, 4], [10, 10], [20, 15], 'x', [1], ['a', 'b'], [95, 140]], 100.0),
		);
		$this->assertSame([], WatchRanges::sanitize('not a list', 100.0));
	}

	public function testTotalSeconds(): void {
		$this->assertEqualsWithDelta(15.0, WatchRanges::totalSeconds([[0.0, 10.0], [20.0, 25.0]]), 0.0001);
	}

	#[DataProvider('userAgents')]
	public function testParsesUserAgents(string $ua, string $browser, string $os, string $device): void {
		$this->assertSame(['browser' => $browser, 'os' => $os, 'deviceType' => $device], UserAgentParser::parse($ua));
	}

	public static function userAgents(): array {
		return [
			'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'Chrome 141', 'Windows', 'desktop'],
			'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', 'Edge 141', 'Windows', 'desktop'],
			'Firefox on Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:144.0) Gecko/20100101 Firefox/144.0', 'Firefox 144', 'Linux', 'desktop'],
			'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.1 Safari/605.1.15', 'Safari 26', 'macOS', 'desktop'],
			'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 26_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.1 Mobile/15E148 Safari/604.1', 'Safari 26', 'iOS 26', 'mobile'],
			'Chrome on iPad' => ['Mozilla/5.0 (iPad; CPU OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.0.0 Mobile/15E148 Safari/604.1', 'Chrome 141', 'iPadOS 18', 'tablet'],
			'Chrome on Android phone' => ['Mozilla/5.0 (Linux; Android 16; Pixel 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', 'Chrome 141', 'Android 16', 'mobile'],
			'Samsung tablet' => ['Mozilla/5.0 (Linux; Android 15; SM-X910) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0.0.0 Safari/537.36', 'Samsung Internet 28', 'Android 15', 'tablet'],
			'Unknown' => ['curl/8.12.1', 'Other', 'Other', 'desktop'],
		];
	}
}

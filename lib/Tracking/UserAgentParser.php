<?php

declare(strict_types=1);

namespace OCA\Screenings\Tracking;

/**
 * Turns a User-Agent string into browser, OS and device type for the
 * dashboard. Deliberately small: it covers the browsers that can play the
 * screening, and reports anything else as "Other".
 */
class UserAgentParser {
	public const DEVICE_DESKTOP = 'desktop';
	public const DEVICE_MOBILE = 'mobile';
	public const DEVICE_TABLET = 'tablet';

	/**
	 * @return array{browser: string, os: string, deviceType: string}
	 */
	public static function parse(string $ua): array {
		return [
			'browser' => self::browser($ua),
			'os' => self::os($ua),
			'deviceType' => self::device($ua),
		];
	}

	private static function browser(string $ua): string {
		// Order matters: Edge, Opera and Samsung also say "Chrome"; Chrome says "Safari".
		$rules = [
			'/Edg(?:e|A|iOS)?\/(\d+)/' => 'Edge',
			'/(?:OPR|Opera)\/(\d+)/' => 'Opera',
			'/SamsungBrowser\/(\d+)/' => 'Samsung Internet',
			'/(?:Firefox|FxiOS)\/(\d+)/' => 'Firefox',
			'/(?:Chrome|CriOS)\/(\d+)/' => 'Chrome',
			'/Version\/(\d+)(?:\.\d+)*.*Safari\//' => 'Safari',
		];
		foreach ($rules as $pattern => $name) {
			if (preg_match($pattern, $ua, $m)) {
				return $name . ' ' . $m[1];
			}
		}
		return 'Other';
	}

	private static function os(string $ua): string {
		if (preg_match('/Windows NT 10\.0/', $ua)) {
			// Windows 11 still reports NT 10.0; the UA can't tell them apart.
			return 'Windows';
		}
		if (preg_match('/Windows NT/', $ua)) {
			return 'Windows (old)';
		}
		if (preg_match('/iPhone OS (\d+)/', $ua, $m)) {
			return 'iOS ' . $m[1];
		}
		if (preg_match('/iPad.*OS (\d+)/', $ua, $m)) {
			return 'iPadOS ' . $m[1];
		}
		if (preg_match('/Android (\d+)/', $ua, $m)) {
			return 'Android ' . $m[1];
		}
		if (str_contains($ua, 'CrOS')) {
			return 'ChromeOS';
		}
		if (str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh')) {
			return 'macOS';
		}
		if (str_contains($ua, 'Linux')) {
			return 'Linux';
		}
		return 'Other';
	}

	private static function device(string $ua): string {
		if (str_contains($ua, 'iPad') || (str_contains($ua, 'Android') && !str_contains($ua, 'Mobile')) || str_contains($ua, 'Tablet')) {
			return self::DEVICE_TABLET;
		}
		if (preg_match('/Mobi|iPhone|iPod|Android/', $ua)) {
			return self::DEVICE_MOBILE;
		}
		return self::DEVICE_DESKTOP;
	}
}

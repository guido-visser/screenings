<?php

declare(strict_types=1);

namespace OCA\Screenings\Stream;

/**
 * Signed values that, together with the HttpOnly session cookie, authorise
 * media requests for one viewer session.
 *
 * - Playback tokens are short-lived (TTL seconds) and bound to the session,
 *   the client IP and the User-Agent. The player renews them through the
 *   heartbeat and sends them as a header on every HLS request, so a URL
 *   copied from devtools carries no credential at all.
 * - Media signatures go into URLs the browser fetches by itself (Direct MP4
 *   ranges, subtitle tracks), which can't carry headers. They are bound to
 *   the session and User-Agent but don't expire on their own: the stream
 *   checks still require an open session with a recent heartbeat.
 */
class PlaybackToken {
	public const TTL = 60;

	public static function create(string $secret, int $sessionId, string $ip, string $userAgent, int $expiresAt): string {
		return $sessionId . '.' . $expiresAt . '.' . self::sign($secret, 'playback', $sessionId, $expiresAt, $ip, $userAgent);
	}

	public static function verify(string $secret, ?string $token, int $sessionId, string $ip, string $userAgent, int $now): bool {
		if ($token === null || !preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/', $token, $m)) {
			return false;
		}
		if ((int)$m[1] !== $sessionId || (int)$m[2] < $now) {
			return false;
		}
		return hash_equals(self::sign($secret, 'playback', $sessionId, (int)$m[2], $ip, $userAgent), $m[3]);
	}

	public static function mediaSignature(string $secret, int $sessionId, string $userAgent, string $resource): string {
		return self::sign($secret, 'media:' . $resource, $sessionId, 0, '', $userAgent);
	}

	public static function verifyMediaSignature(string $secret, ?string $signature, int $sessionId, string $userAgent, string $resource): bool {
		return $signature !== null && hash_equals(self::mediaSignature($secret, $sessionId, $userAgent, $resource), $signature);
	}

	private static function sign(string $secret, string $purpose, int $sessionId, int $expiresAt, string $ip, string $userAgent): string {
		return hash_hmac('sha256', implode('|', ['screenings', $purpose, $sessionId, $expiresAt, $ip, hash('sha256', $userAgent)]), $secret);
	}
}

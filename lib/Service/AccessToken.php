<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

/**
 * Signed "this browser entered the screening password" value, stored in a
 * cookie so viewers aren't asked again on every visit. It is bound to the
 * invite and to the current password hash, so changing the password
 * invalidates every unlock.
 */
class AccessToken {
	public static function create(string $secret, int $inviteId, string $passwordHash, int $expiresAt): string {
		return $inviteId . '.' . $expiresAt . '.' . self::signature($secret, $inviteId, $passwordHash, $expiresAt);
	}

	public static function verify(string $secret, ?string $value, int $inviteId, string $passwordHash, int $now): bool {
		if ($value === null || substr_count($value, '.') !== 2) {
			return false;
		}
		[$id, $expiresAt, $signature] = explode('.', $value);
		if (!ctype_digit($id) || !ctype_digit($expiresAt) || (int)$id !== $inviteId || (int)$expiresAt < $now) {
			return false;
		}
		return hash_equals(self::signature($secret, $inviteId, $passwordHash, (int)$expiresAt), $signature);
	}

	private static function signature(string $secret, int $inviteId, string $passwordHash, int $expiresAt): string {
		return hash_hmac('sha256', 'screenings-access|' . $inviteId . '|' . $expiresAt . '|' . hash('sha256', $passwordHash), $secret);
	}
}

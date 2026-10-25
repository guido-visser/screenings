<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Service;

use OCA\Screenings\Service\AccessToken;
use PHPUnit\Framework\TestCase;

class AccessTokenTest extends TestCase {
	private const SECRET = 'instance-secret';
	private const HASH = '3|$argon2id$v=19$m=65536,t=4,p=1$abc$def';

	public function testValidTokenVerifies(): void {
		$token = AccessToken::create(self::SECRET, 7, self::HASH, 2000);

		$this->assertTrue(AccessToken::verify(self::SECRET, $token, 7, self::HASH, 1999));
	}

	public function testExpiredTokenFails(): void {
		$token = AccessToken::create(self::SECRET, 7, self::HASH, 2000);

		$this->assertFalse(AccessToken::verify(self::SECRET, $token, 7, self::HASH, 2001));
	}

	public function testOtherInviteFails(): void {
		$token = AccessToken::create(self::SECRET, 7, self::HASH, 2000);

		$this->assertFalse(AccessToken::verify(self::SECRET, $token, 8, self::HASH, 1000));
	}

	public function testChangedPasswordFails(): void {
		$token = AccessToken::create(self::SECRET, 7, self::HASH, 2000);

		$this->assertFalse(AccessToken::verify(self::SECRET, $token, 7, self::HASH . 'x', 1000));
	}

	public function testOtherSecretFails(): void {
		$token = AccessToken::create(self::SECRET, 7, self::HASH, 2000);

		$this->assertFalse(AccessToken::verify('other-secret', $token, 7, self::HASH, 1000));
	}

	public function testTamperedExpiryFails(): void {
		[$id, , $signature] = explode('.', AccessToken::create(self::SECRET, 7, self::HASH, 2000));

		$this->assertFalse(AccessToken::verify(self::SECRET, "$id.9999999999.$signature", 7, self::HASH, 1000));
	}

	public function testGarbageFails(): void {
		foreach ([null, '', 'abc', '7.2000', '7.2000.x.y', 'a.b.c'] as $value) {
			$this->assertFalse(AccessToken::verify(self::SECRET, $value, 7, self::HASH, 1000), var_export($value, true));
		}
	}
}

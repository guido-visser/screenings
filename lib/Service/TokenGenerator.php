<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCP\Security\ISecureRandom;

class TokenGenerator {
	/** 32 alphanumeric characters is about 190 bits of entropy. */
	public const LENGTH = 32;

	public function __construct(
		private ISecureRandom $random,
	) {
	}

	public function linkToken(): string {
		return $this->random->generate(self::LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
	}
}

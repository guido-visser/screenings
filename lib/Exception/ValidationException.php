<?php

declare(strict_types=1);

namespace OCA\Screenings\Exception;

/**
 * Invalid input. The message is safe to show to the portal user.
 */
class ValidationException extends \Exception {
	public function __construct(
		string $message,
		private ?string $field = null,
	) {
		parent::__construct($message);
	}

	public function getField(): ?string {
		return $this->field;
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\BackgroundJob;

use OCA\Screenings\Media\MediaProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Packages or transcodes one screening's media.
 */
class ProcessMediaJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private MediaProcessor $processor,
	) {
		parent::__construct($time);
		// One at a time: transcoding is CPU-heavy, packaging is I/O-heavy.
		$this->setAllowParallelRuns(false);
	}

	/**
	 * @param array{screeningId: int, token: string} $argument
	 */
	protected function run($argument): void {
		$this->processor->process((int)$argument['screeningId'], (string)$argument['token']);
	}
}

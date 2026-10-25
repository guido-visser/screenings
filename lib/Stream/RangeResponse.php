<?php

declare(strict_types=1);

namespace OCA\Screenings\Stream;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * A 206 Partial Content response streaming [start, end] of a file, without
 * loading it into memory.
 */
class RangeResponse extends Response implements ICallbackResponse {
	/**
	 * @param callable(): resource $open returns a readable stream of the whole file
	 */
	public function __construct(
		private $open,
		private int $start,
		private int $end,
		int $totalSize,
		string $contentType,
	) {
		parent::__construct(Http::STATUS_PARTIAL_CONTENT);
		$this->addHeader('Content-Type', $contentType);
		$this->addHeader('Content-Length', (string)($end - $start + 1));
		$this->addHeader('Content-Range', "bytes $start-$end/$totalSize");
		$this->addHeader('Accept-Ranges', 'bytes');
	}

	public function callback(IOutput $output): void {
		$stream = ($this->open)();
		if (!is_resource($stream)) {
			return;
		}
		try {
			if (fseek($stream, $this->start) !== 0) {
				// Not seekable: skip ahead by reading.
				$skip = $this->start;
				while ($skip > 0 && !feof($stream)) {
					$skip -= strlen((string)fread($stream, min(1 << 20, $skip)));
				}
			}
			$remaining = $this->end - $this->start + 1;
			while ($remaining > 0 && !feof($stream)) {
				$chunk = fread($stream, min(1 << 16, $remaining));
				if ($chunk === false || $chunk === '') {
					break;
				}
				$output->setOutput($chunk);
				$remaining -= strlen($chunk);
			}
		} finally {
			fclose($stream);
		}
	}
}

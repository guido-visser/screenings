<?php

declare(strict_types=1);

namespace OCA\Screenings\Media;

/**
 * Runs an external program without a shell, streaming its stdout line by
 * line to a callback.
 */
class ProcessRunner {
	/** Only the end of stderr is kept, for error messages. */
	private const STDERR_TAIL = 8192;

	/**
	 * @param list<string> $command program and arguments (no shell parsing)
	 * @param ?callable(string): void $onStdoutLine
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 * @throws MediaException
	 */
	public static function run(array $command, ?int $timeoutSeconds = null, ?callable $onStdoutLine = null): array {
		// stdin is a pipe closed right away (end of input). Not /dev/null:
		// open_basedir would forbid opening it.
		$process = proc_open($command, [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		], $pipes);
		if (!is_resource($process)) {
			throw new MediaException('Could not start ' . basename($command[0]));
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$buffer = '';
		$started = time();

		while (true) {
			$read = [$pipes[1], $pipes[2]];
			$write = null;
			$except = null;
			if (@stream_select($read, $write, $except, 1) === false) {
				break;
			}
			foreach ($read as $pipe) {
				$chunk = fread($pipe, 65536);
				if ($chunk === false || $chunk === '') {
					continue;
				}
				if ($pipe === $pipes[1]) {
					if ($onStdoutLine === null) {
						$stdout .= $chunk;
						continue;
					}
					$buffer .= $chunk;
					while (($pos = strpos($buffer, "\n")) !== false) {
						$onStdoutLine(substr($buffer, 0, $pos));
						$buffer = substr($buffer, $pos + 1);
					}
				} else {
					$stderr = substr($stderr . $chunk, -self::STDERR_TAIL);
				}
			}

			if (feof($pipes[1]) && feof($pipes[2])) {
				break;
			}
			if ($timeoutSeconds !== null && time() - $started > $timeoutSeconds) {
				proc_terminate($process, 9);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
				throw new MediaException(basename($command[0]) . ' timed out');
			}
		}

		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);
		return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
	}
}

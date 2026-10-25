<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Exception\ValidationException;

/**
 * Normalises uploaded subtitle files to WebVTT, which is what browsers play.
 */
class SubtitleConverter {
	/**
	 * @throws ValidationException
	 */
	public function toVtt(string $content, string $fileName): string {
		$content = $this->toUtf8($content);
		$content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
		$content = str_replace(["\r\n", "\r"], "\n", $content);

		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		if ($extension === 'vtt' || str_starts_with($content, 'WEBVTT')) {
			if (!preg_match('/^WEBVTT(?:[ \t][^\n]*)?(?:\n|$)/', $content)) {
				throw new ValidationException('This .vtt file does not start with a WEBVTT header', 'file');
			}
			$this->assertHasCues($content);
			return rtrim($content, "\n") . "\n";
		}

		if ($extension !== 'srt') {
			throw new ValidationException('Upload a .srt or .vtt subtitle file', 'file');
		}

		// SRT timestamps use a comma before the milliseconds; WebVTT uses a dot.
		$vtt = preg_replace(
			'/^(\d{1,2}:\d{2}:\d{2}),(\d{3})\s*-->\s*(\d{1,2}:\d{2}:\d{2}),(\d{3})/m',
			'$1.$2 --> $3.$4',
			trim($content, "\n"),
		);
		$this->assertHasCues($vtt);
		return "WEBVTT\n\n" . $vtt . "\n";
	}

	private function toUtf8(string $content): string {
		if (mb_check_encoding($content, 'UTF-8')) {
			return $content;
		}
		// Most non-UTF-8 subtitle files in the wild are Windows-1252.
		return mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
	}

	/**
	 * @throws ValidationException
	 */
	private function assertHasCues(string $vtt): void {
		if (!preg_match('/^\d{1,2}:\d{2}(?::\d{2})?\.\d{3} --> /m', $vtt)) {
			throw new ValidationException('No subtitle cues found in this file', 'file');
		}
	}
}

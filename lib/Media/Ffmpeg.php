<?php

declare(strict_types=1);

namespace OCA\Screenings\Media;

use OCA\Screenings\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IBinaryFinder;

/**
 * Locates and runs ffmpeg/ffprobe. Only needed for converting files
 * ("Server transcode", and "Auto" for files browsers can't play).
 */
class Ffmpeg {
	public const CONFIG_FFMPEG_PATH = 'ffmpeg_path';
	public const CONFIG_FFPROBE_PATH = 'ffprobe_path';
	/** Tried when PATH lookups find nothing, e.g. because of open_basedir. */
	private const COMMON_DIRS = ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/snap/bin'];

	/** @var array<string, ?string> binary => path, per request */
	private array $resolved = [];

	public function __construct(
		private IAppConfig $appConfig,
		private IBinaryFinder $binaryFinder,
	) {
	}

	public function ffmpegPath(): ?string {
		return $this->path(self::CONFIG_FFMPEG_PATH, 'ffmpeg');
	}

	public function ffprobePath(): ?string {
		return $this->path(self::CONFIG_FFPROBE_PATH, 'ffprobe');
	}

	/**
	 * @return array{ffmpeg: ?array{path: string, version: string}, ffprobe: ?array{path: string, version: string}}
	 */
	public function detect(): array {
		return [
			'ffmpeg' => $this->describe($this->ffmpegPath()),
			'ffprobe' => $this->describe($this->ffprobePath()),
		];
	}

	public function isAvailable(): bool {
		$found = $this->detect();
		return $found['ffmpeg'] !== null && $found['ffprobe'] !== null;
	}

	/**
	 * @return array{durationMs: ?int, height: ?int, videoCodec: ?string, pixFmt: ?string, hasAudio: bool, audioCodec: ?string}
	 * @throws MediaException
	 */
	public function probe(string $localPath): array {
		$ffprobe = $this->ffprobePath() ?? throw new MediaException('ffprobe was not found on the server');
		$result = ProcessRunner::run([$ffprobe, '-v', 'error', '-print_format', 'json', '-show_streams', '-show_format', $localPath]);
		$info = json_decode($result['stdout'], true);
		if ($result['exitCode'] !== 0 || !is_array($info)) {
			throw new MediaException('ffprobe could not read the video: ' . self::lastLine($result['stderr']));
		}

		$video = null;
		$audio = null;
		foreach ($info['streams'] ?? [] as $stream) {
			if (($stream['codec_type'] ?? '') === 'video' && $video === null && empty($stream['disposition']['attached_pic'])) {
				$video = $stream;
			}
			if (($stream['codec_type'] ?? '') === 'audio' && $audio === null) {
				$audio = $stream;
			}
		}
		if ($video === null) {
			throw new MediaException('This file has no video stream');
		}

		$duration = $info['format']['duration'] ?? $video['duration'] ?? null;
		return [
			'durationMs' => is_numeric($duration) ? (int)round((float)$duration * 1000) : null,
			'height' => isset($video['height']) ? (int)$video['height'] : null,
			'videoCodec' => $video['codec_name'] ?? null,
			'pixFmt' => $video['pix_fmt'] ?? null,
			'hasAudio' => $audio !== null,
			'audioCodec' => $audio['codec_name'] ?? null,
		];
	}

	public static function lastLine(string $output): string {
		$lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
		return $lines === [] ? 'unknown error' : mb_substr(end($lines), 0, 300);
	}

	private function path(string $configKey, string $binary): ?string {
		if (array_key_exists($binary, $this->resolved)) {
			return $this->resolved[$binary];
		}
		$configured = trim($this->appConfig->getValueString(Application::APP_ID, $configKey, ''));
		if ($configured !== '') {
			return $this->resolved[$binary] = $this->usable($configured) ? $configured : null;
		}
		$found = $this->binaryFinder->findBinaryPath($binary);
		if (is_string($found) && $found !== '') {
			return $this->resolved[$binary] = $found;
		}
		foreach (self::COMMON_DIRS as $dir) {
			if ($this->usable($dir . '/' . $binary)) {
				return $this->resolved[$binary] = $dir . '/' . $binary;
			}
		}
		return $this->resolved[$binary] = null;
	}

	/**
	 * With open_basedir (e.g. Plesk limits PHP to the site's folder), PHP
	 * can't even check files in /usr/bin, but it can still run them. So
	 * when the check fails, try running the program.
	 */
	private function usable(string $path): bool {
		if (@is_executable($path)) {
			return true;
		}
		if (ini_get('open_basedir') === '' || ini_get('open_basedir') === false) {
			return false;
		}
		try {
			return ProcessRunner::run([$path, '-hide_banner', '-version'], 10)['exitCode'] === 0;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @return ?array{path: string, version: string}
	 */
	private function describe(?string $path): ?array {
		if ($path === null) {
			return null;
		}
		$result = ProcessRunner::run([$path, '-hide_banner', '-version'], 10);
		if ($result['exitCode'] !== 0) {
			return null;
		}
		$firstLine = trim(explode("\n", $result['stdout'])[0] ?? '');
		return ['path' => $path, 'version' => $firstLine];
	}
}

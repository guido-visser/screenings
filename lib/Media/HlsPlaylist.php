<?php

declare(strict_types=1);

namespace OCA\Screenings\Media;

/**
 * Minimal reader/writer for the HLS playlists the app accepts and produces:
 * VOD, MPEG-TS segments, unencrypted input, AES-128 output.
 */
class HlsPlaylist {
	/**
	 * Splits a master playlist into its variants. Returns null when $text is
	 * a media playlist (segments directly, no variants).
	 *
	 * @return ?list<array{uri: string, attributes: string}>
	 * @throws MediaException
	 */
	public static function parseMaster(string $text): ?array {
		$lines = self::lines($text);
		if (!str_contains($text, '#EXT-X-STREAM-INF')) {
			return null;
		}
		$variants = [];
		$pending = null;
		foreach ($lines as $line) {
			if (str_starts_with($line, '#EXT-X-MEDIA:') && str_contains($line, 'URI=')) {
				throw new MediaException('Playlists with separate audio or subtitle renditions (#EXT-X-MEDIA) are not supported. Export audio and video together, and add subtitles in the screening instead.');
			}
			if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
				$pending = substr($line, strlen('#EXT-X-STREAM-INF:'));
			} elseif ($pending !== null && $line !== '' && !str_starts_with($line, '#')) {
				self::assertRelative($line);
				$variants[] = ['uri' => $line, 'attributes' => $pending];
				$pending = null;
			}
		}
		if ($variants === []) {
			throw new MediaException('The master playlist lists no variants');
		}
		return $variants;
	}

	/**
	 * @param bool $ownOutput true when reading the app's own (encrypted) output
	 *                        rather than an upload, which must be unencrypted
	 * @return array{mediaSequence: int, targetDuration: int, segments: list<array{duration: float, uri: string}>}
	 * @throws MediaException
	 */
	public static function parseMedia(string $text, bool $ownOutput = false): array {
		$lines = self::lines($text);
		if (($lines[0] ?? '') !== '#EXTM3U') {
			throw new MediaException('This is not an HLS playlist (it does not start with #EXTM3U)');
		}

		$mediaSequence = 0;
		$targetDuration = 0;
		$segments = [];
		$duration = null;
		$ended = false;

		foreach ($lines as $line) {
			if (!$ownOutput && str_starts_with($line, '#EXT-X-KEY:') && !str_contains($line, 'METHOD=NONE')) {
				throw new MediaException('This HLS is already encrypted. Upload an unencrypted export; the app encrypts it.');
			}
			if (str_starts_with($line, '#EXT-X-MAP:')) {
				throw new MediaException('Fragmented MP4 (fMP4) HLS is not supported yet. Export with MPEG-TS segments (ffmpeg -hls_segment_type mpegts).');
			}
			if (str_starts_with($line, '#EXT-X-BYTERANGE:')) {
				throw new MediaException('HLS with byte-range segments is not supported. Export one file per segment.');
			}
			if (str_starts_with($line, '#EXT-X-MEDIA-SEQUENCE:')) {
				$mediaSequence = (int)substr($line, strlen('#EXT-X-MEDIA-SEQUENCE:'));
			} elseif (str_starts_with($line, '#EXT-X-TARGETDURATION:')) {
				$targetDuration = (int)substr($line, strlen('#EXT-X-TARGETDURATION:'));
			} elseif (str_starts_with($line, '#EXTINF:')) {
				$duration = (float)explode(',', substr($line, strlen('#EXTINF:')), 2)[0];
			} elseif ($line === '#EXT-X-ENDLIST') {
				$ended = true;
			} elseif ($line !== '' && !str_starts_with($line, '#')) {
				if ($duration === null) {
					throw new MediaException('Segment "' . $line . '" has no #EXTINF duration');
				}
				self::assertRelative($line);
				$segments[] = ['duration' => $duration, 'uri' => $line];
				$duration = null;
			}
		}

		if (!$ended) {
			throw new MediaException('This playlist is not a finished VOD playlist (#EXT-X-ENDLIST is missing)');
		}
		if ($segments === []) {
			throw new MediaException('This playlist has no segments');
		}
		if ($targetDuration === 0) {
			$targetDuration = (int)ceil(max(array_column($segments, 'duration')));
		}

		return ['mediaSequence' => $mediaSequence, 'targetDuration' => $targetDuration, 'segments' => $segments];
	}

	/**
	 * An encrypted VOD media playlist. Segment i uses IV = media sequence + i
	 * (the HLS default when the key tag has no IV attribute).
	 *
	 * @param list<array{duration: float, uri: string}> $segments with output URIs
	 */
	public static function buildMedia(int $targetDuration, int $mediaSequence, array $segments): string {
		$out = [
			'#EXTM3U',
			'#EXT-X-VERSION:3',
			'#EXT-X-PLAYLIST-TYPE:VOD',
			'#EXT-X-TARGETDURATION:' . $targetDuration,
			'#EXT-X-MEDIA-SEQUENCE:' . $mediaSequence,
			'#EXT-X-KEY:METHOD=AES-128,URI="' . MediaStorage::KEY_URI_PLACEHOLDER . '"',
		];
		foreach ($segments as $segment) {
			$out[] = '#EXTINF:' . self::formatDuration($segment['duration']) . ',';
			$out[] = $segment['uri'];
		}
		$out[] = '#EXT-X-ENDLIST';
		return implode("\n", $out) . "\n";
	}

	/**
	 * @param list<array{uri: string, attributes: string}> $variants with output URIs
	 */
	public static function buildMaster(array $variants): string {
		$out = ['#EXTM3U', '#EXT-X-VERSION:3'];
		foreach ($variants as $variant) {
			$out[] = '#EXT-X-STREAM-INF:' . $variant['attributes'];
			$out[] = $variant['uri'];
		}
		return implode("\n", $out) . "\n";
	}

	/**
	 * The 16-byte IV for a segment's media sequence number.
	 */
	public static function iv(int $sequence): string {
		return str_repeat("\0", 8) . pack('J', $sequence);
	}

	public static function totalDurationMs(array $segments): int {
		return (int)round(array_sum(array_column($segments, 'duration')) * 1000);
	}

	/**
	 * @return list<string>
	 */
	private static function lines(string $text): array {
		$text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
		return array_map('trim', explode("\n", str_replace("\r", '', $text)));
	}

	/**
	 * @throws MediaException
	 */
	private static function assertRelative(string $uri): void {
		if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $uri) || str_starts_with($uri, '/')) {
			throw new MediaException('Playlist entries must be relative file names, not URLs or absolute paths ("' . $uri . '")');
		}
	}

	private static function formatDuration(float $seconds): string {
		return rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.') ?: '0';
	}
}

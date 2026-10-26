<?php

declare(strict_types=1);

namespace OCA\Screenings\Media;

/**
 * Reads an MP4's box structure without decoding anything: where the index
 * (moov) sits, the duration, and the track codecs. Only the moov box is
 * loaded into memory; media data is skipped with seeks.
 */
class Mp4Probe {
	/** moov boxes larger than this are not plausible for a single film. */
	private const MAX_MOOV_BYTES = 64 * 1024 * 1024;
	private const CONTAINERS = ['trak', 'mdia', 'minf', 'stbl'];

	/** Codecs every current browser plays from an MP4. */
	public const BROWSER_VIDEO_CODECS = ['avc1', 'avc3', 'av01', 'vp09'];
	public const BROWSER_AUDIO_CODECS = ['mp4a', 'Opus', 'fLaC'];
	/**
	 * H.264 profiles browsers decode: Baseline, Main, Extended, High. High 10,
	 * High 4:2:2 and High 4:4:4 (10-bit or pro exports) don't play.
	 */
	public const BROWSER_AVC_PROFILES = [66, 77, 88, 100];
	/** Size of a VisualSampleEntry before its child boxes (header included). */
	private const VISUAL_ENTRY_HEADER = 86;

	/**
	 * @param resource $stream seekable stream positioned anywhere
	 * @throws MediaException
	 */
	public function probe($stream, int $fileSize): Mp4Info {
		$offset = 0;
		$moov = null;
		$moovBeforeMdat = null;
		$moovOffset = 0;
		$moovSize = 0;
		$first = true;

		while ($offset + 8 <= $fileSize) {
			[$type, $headerSize, $boxSize] = $this->readHeader($stream, $offset, $fileSize);
			if ($first && $type !== 'ftyp') {
				throw new MediaException('This file is not an MP4 (it does not start with an "ftyp" box)');
			}
			$first = false;

			if ($type === 'mdat' && $moovBeforeMdat === null) {
				$moovBeforeMdat = false;
			}
			if ($type === 'moov') {
				if ($moovBeforeMdat === null) {
					$moovBeforeMdat = true;
				}
				$bodySize = $boxSize - $headerSize;
				if ($bodySize > self::MAX_MOOV_BYTES) {
					throw new MediaException('The MP4 index is unexpectedly large');
				}
				$moov = $this->readAt($stream, $offset + $headerSize, $bodySize);
				$moovOffset = $offset;
				$moovSize = $boxSize;
				break;
			}
			$offset += $boxSize;
		}

		if ($moov === null) {
			throw new MediaException('This MP4 has no index (moov box); it may be incomplete or still uploading');
		}

		return $this->parseMoov($moov, $moovBeforeMdat ?? true, $moovOffset, $moovSize);
	}

	private function parseMoov(string $moov, bool $faststart, int $moovOffset, int $moovSize): Mp4Info {
		$durationMs = null;
		$videoCodec = null;
		$videoProfile = null;
		$audioCodec = null;

		foreach ($this->boxes($moov, 0, strlen($moov)) as [$type, $start, $end]) {
			if ($type === 'mvhd') {
				$durationMs = $this->parseMvhd(substr($moov, $start, $end - $start));
			} elseif ($type === 'trak') {
				[$handler, $codec, $profile] = $this->parseTrak($moov, $start, $end);
				if ($handler === 'vide' && $videoCodec === null) {
					$videoCodec = $codec;
					$videoProfile = $profile;
				} elseif ($handler === 'soun' && $audioCodec === null) {
					$audioCodec = $codec;
				}
			}
		}

		return new Mp4Info($durationMs, $faststart, $videoCodec, $audioCodec, $moovOffset, $moovSize, $videoProfile);
	}

	/**
	 * @return array{?string, ?string, ?int} handler type ("vide", "soun", …), codec fourcc,
	 *                                       and for H.264 the profile_idc
	 */
	private function parseTrak(string $data, int $start, int $end): array {
		$handler = null;
		$codec = null;
		$profile = null;
		$walk = function (int $from, int $to) use (&$walk, &$handler, &$codec, &$profile, $data): void {
			foreach ($this->boxes($data, $from, $to) as [$type, $s, $e]) {
				if (in_array($type, self::CONTAINERS, true)) {
					$walk($s, $e);
				} elseif ($type === 'hdlr' && $e - $s >= 12) {
					// version/flags (4), pre_defined (4), handler_type (4)
					$handler = substr($data, $s + 8, 4);
				} elseif ($type === 'stsd' && $e - $s >= 16) {
					// version/flags (4), entry_count (4), then the first entry's size (4) and type (4)
					$codec = substr($data, $s + 12, 4);
					if ($codec === 'avc1' || $codec === 'avc3') {
						$entryEnd = min($e, $s + 8 + unpack('N', substr($data, $s + 8, 4))[1]);
						$profile = $this->avcProfile($data, $s + 8 + self::VISUAL_ENTRY_HEADER, $entryEnd);
					}
				}
			}
		};
		$walk($start, $end);
		return [$handler, $codec, $profile];
	}

	/**
	 * profile_idc from the avcC box among a sample entry's children:
	 * configurationVersion (1), then AVCProfileIndication (1).
	 */
	private function avcProfile(string $data, int $from, int $to): ?int {
		foreach ($this->boxes($data, $from, $to) as [$type, $s, $e]) {
			if ($type === 'avcC' && $e - $s >= 2) {
				return ord($data[$s + 1]);
			}
		}
		return null;
	}

	private function parseMvhd(string $body): ?int {
		if (strlen($body) < 20) {
			return null;
		}
		$version = ord($body[0]);
		if ($version === 1) {
			if (strlen($body) < 32) {
				return null;
			}
			$timescale = unpack('N', substr($body, 20, 4))[1];
			$duration = unpack('J', substr($body, 24, 8))[1];
		} else {
			$timescale = unpack('N', substr($body, 12, 4))[1];
			$duration = unpack('N', substr($body, 16, 4))[1];
		}
		if ($timescale === 0) {
			return null;
		}
		return intdiv($duration * 1000, $timescale);
	}

	/**
	 * Iterates the boxes inside $data[$start, $end).
	 *
	 * @return \Generator<array{string, int, int}> type, body start, body end
	 */
	private function boxes(string $data, int $start, int $end): \Generator {
		$offset = $start;
		while ($offset + 8 <= $end) {
			$size = unpack('N', substr($data, $offset, 4))[1];
			$type = substr($data, $offset + 4, 4);
			$header = 8;
			if ($size === 1) {
				if ($offset + 16 > $end) {
					return;
				}
				$size = unpack('J', substr($data, $offset + 8, 8))[1];
				$header = 16;
			} elseif ($size === 0) {
				$size = $end - $offset;
			}
			if ($size < $header || $offset + $size > $end) {
				return;
			}
			yield [$type, $offset + $header, $offset + $size];
			$offset += $size;
		}
	}

	/**
	 * @return array{string, int, int} type, header size, box size
	 * @throws MediaException
	 */
	private function readHeader($stream, int $offset, int $fileSize): array {
		$header = $this->readAt($stream, $offset, 8);
		$size = unpack('N', substr($header, 0, 4))[1];
		$type = substr($header, 4, 4);
		$headerSize = 8;
		if ($size === 1) {
			$size = unpack('J', $this->readAt($stream, $offset + 8, 8))[1];
			$headerSize = 16;
		} elseif ($size === 0) {
			$size = $fileSize - $offset;
		}
		if ($size < $headerSize) {
			throw new MediaException('This MP4 is damaged (invalid box size)');
		}
		return [$type, $headerSize, $size];
	}

	/**
	 * @throws MediaException
	 */
	private function readAt($stream, int $offset, int $length): string {
		if (fseek($stream, $offset) !== 0) {
			throw new MediaException('Could not read the video file');
		}
		$data = '';
		while (strlen($data) < $length && !feof($stream)) {
			$chunk = fread($stream, $length - strlen($data));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$data .= $chunk;
		}
		if (strlen($data) < $length) {
			throw new MediaException('This MP4 is truncated');
		}
		return $data;
	}

	/**
	 * Whether every current browser plays this MP4 as it is. An unknown H.264
	 * profile counts as fine: older files may lack the avcC box we read.
	 */
	public static function browserSafe(Mp4Info $info): bool {
		if (!in_array($info->videoCodec, self::BROWSER_VIDEO_CODECS, true)) {
			return false;
		}
		if (in_array($info->videoCodec, ['avc1', 'avc3'], true) && $info->videoProfile !== null
			&& !in_array($info->videoProfile, self::BROWSER_AVC_PROFILES, true)) {
			return false;
		}
		return $info->audioCodec === null || in_array($info->audioCodec, self::BROWSER_AUDIO_CODECS, true);
	}

	/**
	 * Owner-facing warnings for an MP4 played directly (Direct MP4 mode).
	 * Auto never plays such files directly; it converts them.
	 *
	 * @return string[]
	 */
	public static function warnings(Mp4Info $info): array {
		$warnings = [];
		if ($info->videoCodec !== null && !in_array($info->videoCodec, self::BROWSER_VIDEO_CODECS, true)) {
			$warnings[] = in_array($info->videoCodec, ['hvc1', 'hev1'], true)
				? 'The video is HEVC (H.265), which many browsers cannot play (e.g. Firefox and most Chrome on Windows/Linux). Choose Auto to convert it.'
				: sprintf('The video codec "%s" may not play in browsers. Choose Auto to convert it.', $info->videoCodec);
		} elseif ($info->videoProfile !== null && in_array($info->videoCodec, ['avc1', 'avc3'], true)
			&& !in_array($info->videoProfile, self::BROWSER_AVC_PROFILES, true)) {
			$warnings[] = 'The video is a 10-bit or 4:2:2/4:4:4 H.264 export, which browsers cannot play. Choose Auto to convert it.';
		}
		if ($info->audioCodec !== null && !in_array($info->audioCodec, self::BROWSER_AUDIO_CODECS, true)) {
			$warnings[] = sprintf('The audio codec "%s" may not play in browsers. Choose Auto to convert it.', $info->audioCodec);
		}
		return $warnings;
	}
}

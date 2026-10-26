<?php

declare(strict_types=1);

namespace OCA\Screenings\Media;

class Mp4Info {
	/**
	 * @param bool $faststart whether the index (moov) comes before the media data
	 * @param ?string $videoCodec sample entry fourcc, e.g. "avc1" or "hvc1"
	 * @param ?string $audioCodec sample entry fourcc, e.g. "mp4a"
	 * @param int $moovOffset byte offset of the moov box (the index players read first)
	 * @param ?int $videoProfile H.264 profile_idc from the avcC box (100 = High, 110 = High 10, ...)
	 */
	public function __construct(
		public readonly ?int $durationMs,
		public readonly bool $faststart,
		public readonly ?string $videoCodec,
		public readonly ?string $audioCodec,
		public readonly int $moovOffset = 0,
		public readonly int $moovSize = 0,
		public readonly ?int $videoProfile = null,
	) {
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\Media;

use OCP\IBinaryFinder;

/**
 * "Server transcode" mode: ffmpeg turns the source into plain HLS, either
 * re-encoded in up to three qualities or, in "remux" mode, repackaged without
 * re-encoding (fast, single quality, H.264 sources only). HlsPackager then
 * encrypts it, because ffmpeg's own HLS encryption reuses one IV for every
 * segment.
 */
class Transcoder {
	public const MODE_FULL = 'full';
	public const MODE_REMUX = 'remux';

	/** Height => video bitrate (kbit/s). */
	public const LADDER = [1080 => 5000, 720 => 2800, 480 => 1400];
	private const AUDIO_KBPS = 128;
	private const SEGMENT_SECONDS = 4;

	public function __construct(
		private Ffmpeg $ffmpeg,
		private IBinaryFinder $binaryFinder,
	) {
	}

	/**
	 * Writes an unencrypted master.m3u8, v<N>.m3u8 and v<N>_<NNNNN>.ts into $outDir.
	 *
	 * @param callable(float): void $onProgress receives 0..1
	 * @throws MediaException
	 */
	public function transcode(string $source, string $outDir, string $mode, callable $onProgress): void {
		$ffmpeg = $this->ffmpeg->ffmpegPath() ?? throw new MediaException('ffmpeg was not found on the server');
		$info = $this->ffmpeg->probe($source);
		$durationMs = $info['durationMs'] ?? 0;
		// A trailing slash ("dir//v0.m3u8") makes ffmpeg write "/v0.m3u8" into the master playlist.
		$outDir = rtrim($outDir, '/');

		$args = $mode === self::MODE_REMUX
			? $this->remuxArgs($info)
			: $this->encodeArgs($info);

		$command = array_merge(
			$this->niceWrapper(),
			[$ffmpeg, '-hide_banner', '-nostdin', '-y', '-i', $source],
			$args['codec'],
			[
				'-f', 'hls',
				'-hls_time', (string)self::SEGMENT_SECONDS,
				'-hls_playlist_type', 'vod',
				'-hls_flags', 'independent_segments',
				'-hls_segment_type', 'mpegts',
				'-hls_segment_filename', $outDir . '/v%v_%05d.ts',
				'-master_pl_name', MediaStorage::MASTER_PLAYLIST,
				'-var_stream_map', $args['streamMap'],
				'-progress', 'pipe:1', '-nostats',
				$outDir . '/v%v.m3u8',
			],
		);

		$result = ProcessRunner::run($command, null, function (string $line) use ($durationMs, $onProgress): void {
			// ffmpeg reports out_time_us (and, misleadingly, out_time_ms) in microseconds.
			if ($durationMs > 0 && preg_match('/^out_time_us=(\d+)/', $line, $m)) {
				$onProgress(min(1.0, ((int)$m[1] / 1000) / $durationMs));
			}
		});

		if ($result['exitCode'] !== 0) {
			throw new MediaException('ffmpeg failed: ' . Ffmpeg::lastLine($result['stderr']));
		}
		if (!is_file($outDir . '/' . MediaStorage::MASTER_PLAYLIST)) {
			throw new MediaException('ffmpeg finished without writing a playlist');
		}
	}

	/**
	 * The renditions to produce for a source height: every ladder step at or
	 * below it, or just the source height when it is smaller than all steps.
	 *
	 * @return array<int, int> height => kbit/s
	 */
	public static function renditions(?int $sourceHeight): array {
		if ($sourceHeight === null) {
			return [720 => self::LADDER[720]];
		}
		$steps = array_filter(self::LADDER, fn (int $height) => $height <= $sourceHeight, ARRAY_FILTER_USE_KEY);
		if ($steps === []) {
			$even = $sourceHeight - ($sourceHeight % 2);
			return [$even => self::LADDER[480]];
		}
		return $steps;
	}

	/**
	 * @return array{codec: list<string>, streamMap: string}
	 */
	private function encodeArgs(array $info): array {
		$renditions = self::renditions($info['height']);
		$count = count($renditions);

		$labels = array_map(fn (int $i) => "[s$i]", range(0, $count - 1));
		$filter = '[0:v:0]split=' . $count . implode('', $labels);
		$i = 0;
		foreach (array_keys($renditions) as $height) {
			$filter .= ";[s$i]scale=-2:$height,format=yuv420p[v$i]";
			$i++;
		}

		$codec = ['-filter_complex', $filter];
		$map = [];
		$i = 0;
		foreach ($renditions as $kbps) {
			$codec = array_merge($codec, [
				'-map', "[v$i]",
				"-b:v:$i", $kbps . 'k',
				"-maxrate:v:$i", (int)($kbps * 1.07) . 'k',
				"-bufsize:v:$i", ($kbps * 2) . 'k',
			]);
			if ($info['hasAudio']) {
				$codec = array_merge($codec, ['-map', '0:a:0']);
				$map[] = "v:$i,a:$i";
			} else {
				$map[] = "v:$i";
			}
			$i++;
		}

		$codec = array_merge($codec, [
			'-c:v', 'libx264', '-preset', 'veryfast', '-profile:v', 'high',
			'-force_key_frames', 'expr:gte(t,n_forced*' . self::SEGMENT_SECONDS . ')',
			'-sc_threshold', '0',
		]);
		if ($info['hasAudio']) {
			$codec = array_merge($codec, ['-c:a', 'aac', '-b:a', self::AUDIO_KBPS . 'k', '-ac', '2']);
		}

		return ['codec' => $codec, 'streamMap' => implode(' ', $map)];
	}

	/**
	 * @return array{codec: list<string>, streamMap: string}
	 * @throws MediaException
	 */
	private function remuxArgs(array $info): array {
		if ($info['videoCodec'] !== 'h264') {
			throw new MediaException(sprintf('Remux-only transcoding needs H.264 video, but this file is %s. Ask the admin to enable full transcoding, or re-export as H.264.', $info['videoCodec'] ?? 'unknown'));
		}
		$codec = ['-map', '0:v:0'];
		if ($info['hasAudio']) {
			$codec = array_merge($codec, ['-map', '0:a:0']);
		}
		$codec = array_merge($codec, ['-c', 'copy']);
		return ['codec' => $codec, 'streamMap' => $info['hasAudio'] ? 'v:0,a:0' : 'v:0'];
	}

	/**
	 * Runs ffmpeg at low priority so the web server stays responsive.
	 *
	 * @return list<string>
	 */
	private function niceWrapper(): array {
		$nice = $this->binaryFinder->findBinaryPath('nice');
		return is_string($nice) && $nice !== '' ? [$nice, '-n', '10'] : [];
	}
}

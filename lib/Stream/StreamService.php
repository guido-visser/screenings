<?php

declare(strict_types=1);

namespace OCA\Screenings\Stream;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Db\ViewerSessionMapper;
use OCA\Screenings\Media\HlsPlaylist;
use OCA\Screenings\Media\MediaException;
use OCA\Screenings\Media\MediaService;
use OCA\Screenings\Media\MediaStorage;
use OCA\Screenings\Media\Mp4Probe;
use OCA\Screenings\Service\SubtitleService;
use OCA\Screenings\Tracking\TrackingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Serves a session's media: encrypted HLS (playlists, key, segments) or a
 * Direct MP4 in byte ranges, plus subtitle tracks.
 */
class StreamService {
	/** Direct MP4 responses carry at most this many bytes. */
	public const MP4_CHUNK_BYTES = 2 * 1024 * 1024;
	public const HEARTBEAT_SECONDS = 5;

	private ICache $cache;

	public function __construct(
		private StreamGuard $guard,
		private PlaybackBudget $budget,
		private MediaStorage $storage,
		private MediaService $media,
		private Mp4Probe $mp4Probe,
		private SubtitleService $subtitles,
		private ViewerSessionMapper $sessions,
		private TrackingService $tracking,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $time,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createLocal('screenings-stream');
	}

	/**
	 * What the player needs, embedded in the screening page.
	 */
	public function playerConfig(StreamContext $ctx, IRequest $request): array {
		$sid = $ctx->session->getId();
		$hls = $ctx->screening->getDeliveryMode() !== Screening::MODE_MP4;
		$route = fn (string $name, array $params = []) => $this->urlGenerator->linkToRoute(Application::APP_ID . '.stream.' . $name, ['sessionId' => $sid] + $params);

		$subtitles = [];
		foreach ($this->subtitles->listForScreening($ctx->screening->getId()) as $subtitle) {
			$subtitles[] = [
				'id' => $subtitle->getId(),
				'lang' => $subtitle->getLang(),
				'label' => $subtitle->getLabel(),
				'isDefault' => (bool)$subtitle->getIsDefault(),
				'src' => $route('subtitle', ['subtitleId' => $subtitle->getId()]) . '?sig=' . $this->guard->mediaSignature($sid, $request, 'vtt:' . $subtitle->getId()),
			];
		}

		return [
			'sessionId' => $sid,
			'mode' => $hls ? 'hls' : 'mp4',
			'src' => $hls
				? $route('file', ['file' => MediaStorage::MASTER_PLAYLIST])
				: $route('mp4') . '?sig=' . $this->guard->mediaSignature($sid, $request, 'mp4'),
			'heartbeatUrl' => $route('heartbeat'),
			'eventsUrl' => $route('events'),
			'heartbeatSeconds' => self::HEARTBEAT_SECONDS,
			'tokenHeader' => StreamGuard::TOKEN_HEADER,
			'subtitles' => $subtitles,
		] + $this->guard->playbackToken($sid, $request);
	}

	/**
	 * A file of the encrypted HLS package: a playlist, the key or a segment.
	 *
	 * @return array{content: string, type: string}
	 * @throws StreamDenied
	 */
	public function hlsFile(StreamContext $ctx, string $file): array {
		$screening = $ctx->screening;
		if ($screening->getDeliveryMode() === Screening::MODE_MP4 || $screening->getMediaToken() === null) {
			throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
		}

		if (preg_match('/^v(\d+)_(\d{5})\.ts$/', $file, $m)) {
			$segments = $this->segmentStarts($screening, (int)$m[1]);
			$index = (int)$m[2];
			if (!isset($segments[$index])) {
				throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
			}
			[$start, $duration] = $segments[$index];
			if (!PlaybackBudget::withinWindow($start, $ctx->session->getLastPositionS())) {
				throw new StreamDenied(StreamDenied::AHEAD);
			}
			$this->budget->consume($ctx->session->getId(), $this->time->getTime() - $ctx->session->getStartedAt(), $duration);
			return ['content' => $this->read($screening, $file), 'type' => 'video/mp2t'];
		}

		if ($file === MediaStorage::KEY_URI_PLACEHOLDER) {
			return ['content' => $this->read($screening, MediaStorage::KEY_FILE), 'type' => 'application/octet-stream'];
		}
		if ($file === MediaStorage::MASTER_PLAYLIST || preg_match('/^v\d+\.m3u8$/', $file)) {
			return ['content' => $this->read($screening, $file), 'type' => 'application/vnd.apple.mpegurl'];
		}
		throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
	}

	/**
	 * A byte range of a Direct MP4, at most MP4_CHUNK_BYTES long.
	 *
	 * @throws StreamDenied
	 */
	public function mp4Range(StreamContext $ctx, ?string $rangeHeader): RangeResponse {
		$screening = $ctx->screening;
		$file = $screening->getDeliveryMode() === Screening::MODE_MP4 ? $this->media->sourceFile($screening) : null;
		if ($file === null) {
			throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
		}
		$size = $file->getSize();
		[$start, $end] = self::parseRange($rangeHeader, $size);
		$end = min($end, $start + self::MP4_CHUNK_BYTES - 1);

		$layout = $this->mp4Layout($file);
		$inIndex = $start >= $layout['moovOffset'] && $start < $layout['moovOffset'] + $layout['moovSize'];
		if (!$inIndex) {
			// Map the byte offset to an approximate time to apply the playhead
			// window. Bitrates vary, so allow 50% slack on top of the window.
			$bytesPerSecond = $layout['durationS'] > 0 ? $size / $layout['durationS'] : $size;
			$approxTime = $start / $bytesPerSecond / 1.5;
			if (!PlaybackBudget::withinWindow($approxTime, $ctx->session->getLastPositionS())) {
				throw new StreamDenied(StreamDenied::AHEAD);
			}
			$this->budget->consume($ctx->session->getId(), $this->time->getTime() - $ctx->session->getStartedAt(), ($end - $start + 1) / $bytesPerSecond);
		}

		return new RangeResponse(fn () => MediaService::seekableStream($file), $start, $end, $size, 'video/mp4');
	}

	/**
	 * @throws StreamDenied
	 */
	public function subtitle(StreamContext $ctx, int $subtitleId): string {
		try {
			$subtitle = $this->subtitles->get($subtitleId);
			if ($subtitle->getScreeningId() !== $ctx->screening->getId()) {
				throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
			}
			return $this->subtitles->content($subtitle);
		} catch (\OCA\Screenings\Exception\NotFoundException) {
			throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
		}
	}

	/**
	 * Records the player's state and hands out a fresh playback token.
	 */
	public function heartbeat(StreamContext $ctx, IRequest $request, array $state): array {
		$session = $ctx->session;
		$durationS = ($ctx->screening->getDurationMs() ?? 0) / 1000;
		// Before last_heartbeat_at moves: the plausibility check needs the gap.
		$this->tracking->recordRanges($session, $durationS, $state['ranges'] ?? null);
		if (isset($state['position']) && is_numeric($state['position'])) {
			$position = max(0.0, (float)$state['position']);
			$session->setLastPositionS($durationS > 0 ? min($position, $durationS) : $position);
		}
		$session->setPlaying(!empty($state['playing']));
		$session->setCurrentSubtitle(self::shortString($state['subtitle'] ?? null));
		$session->setCurrentQuality(self::shortString($state['quality'] ?? null));
		$session->setLastHeartbeatAt($this->time->getTime());
		$this->sessions->update($session);

		return ['status' => 'ok'] + $this->guard->playbackToken($session->getId(), $request);
	}

	/**
	 * Parses a single "bytes=start-end" range (open-ended and suffix ranges
	 * included). No header means the whole file.
	 *
	 * @return array{int, int} inclusive start and end
	 * @throws StreamDenied 416 for unsatisfiable or multiple ranges
	 */
	public static function parseRange(?string $header, int $size): array {
		if ($size <= 0) {
			throw new StreamDenied('range', 416);
		}
		if ($header === null || $header === '') {
			return [0, $size - 1];
		}
		if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) || ($m[1] === '' && $m[2] === '')) {
			throw new StreamDenied('range', 416);
		}
		if ($m[1] === '') {
			// Suffix range: the last N bytes.
			$start = max(0, $size - (int)$m[2]);
			$end = $size - 1;
		} else {
			$start = (int)$m[1];
			$end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
		}
		if ($start >= $size || $start > $end) {
			throw new StreamDenied('range', 416);
		}
		return [$start, $end];
	}

	/**
	 * @return list<array{float, float}> per segment: start time and duration
	 * @throws StreamDenied
	 */
	private function segmentStarts(Screening $screening, int $variant): array {
		$key = 'segments-' . MediaStorage::folderName($screening->getId(), $screening->getMediaToken()) . '-' . $variant;
		$cached = $this->cache->get($key);
		if (is_array($cached)) {
			return $cached;
		}
		try {
			$playlist = HlsPlaylist::parseMedia($this->read($screening, sprintf('v%d.m3u8', $variant)), true);
		} catch (MediaException) {
			throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
		}
		$starts = [];
		$time = 0.0;
		foreach ($playlist['segments'] as $segment) {
			$starts[] = [$time, $segment['duration']];
			$time += $segment['duration'];
		}
		$this->cache->set($key, $starts, 3600);
		return $starts;
	}

	/**
	 * @return array{moovOffset: int, moovSize: int, durationS: float}
	 */
	private function mp4Layout(File $file): array {
		$key = 'mp4-' . $file->getId() . '-' . $file->getMTime() . '-' . $file->getSize();
		$cached = $this->cache->get($key);
		if (is_array($cached)) {
			return $cached;
		}
		try {
			$stream = MediaService::seekableStream($file);
			try {
				$info = $this->mp4Probe->probe($stream, $file->getSize());
			} finally {
				fclose($stream);
			}
		} catch (MediaException) {
			throw new StreamDenied(StreamDenied::NOT_READY);
		}
		$layout = ['moovOffset' => $info->moovOffset, 'moovSize' => $info->moovSize, 'durationS' => ($info->durationMs ?? 0) / 1000];
		$this->cache->set($key, $layout, 3600);
		return $layout;
	}

	/**
	 * @throws StreamDenied
	 */
	private function read(Screening $screening, string $name): string {
		try {
			return $this->storage->getFolder($screening->getId(), $screening->getMediaToken())->getFile($name)->getContent();
		} catch (NotFoundException) {
			throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
		}
	}

	private static function shortString(mixed $value): ?string {
		if (!is_string($value) || $value === '') {
			return null;
		}
		return mb_substr($value, 0, 16);
	}
}

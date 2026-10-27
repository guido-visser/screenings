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
use OCA\Screenings\Service\QuestionService;
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
	/** How far before the MP4 index a range may start and still count as reading it. */
	public const INDEX_MARGIN_BYTES = 1024 * 1024;
	/** A viewer's MP4 chunk never holds more film than this (see mp4ChunkBytes). */
	public const MP4_CHUNK_SECONDS = 20;
	public const MP4_MIN_CHUNK_BYTES = 64 * 1024;
	public const HEARTBEAT_SECONDS = 5;

	private ICache $cache;

	public function __construct(
		private StreamGuard $guard,
		private PlaybackBudget $budget,
		private MediaStorage $storage,
		private MediaService $media,
		private Mp4Probe $mp4Probe,
		private SubtitleService $subtitles,
		private QuestionService $questions,
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
		$hls = $ctx->screening->servedAs() !== Screening::MODE_MP4;
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
			// Only for invites the owner asked for notes.
			'notesUrl' => $ctx->invite->mayLeaveNotes()
				? $this->urlGenerator->linkToRoute(Application::APP_ID . '.feedback.notes', ['sessionId' => $sid])
				: null,
			// Closing questions: {url, count, answered, pending}, or null.
			'questions' => ($questions = $this->questions->viewerState($ctx)) === null ? null : $questions + [
				'url' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.feedback.questions', ['sessionId' => $sid]),
			],
			'heartbeatSeconds' => self::HEARTBEAT_SECONDS,
			'tokenHeader' => StreamGuard::TOKEN_HEADER,
			'subtitles' => $subtitles,
		] + $this->guard->playbackToken($sid, $request);
	}

	/**
	 * What the owner's preview player needs (the notes review page). Plain
	 * URLs: the owner's own Nextcloud login authorizes them.
	 */
	public function previewConfig(Screening $screening): array {
		$id = $screening->getId();
		$hls = $screening->servedAs() !== Screening::MODE_MP4;
		$route = fn (string $name, array $params = []) => $this->urlGenerator->linkToRoute(Application::APP_ID . '.preview.' . $name, ['id' => $id] + $params);

		$subtitles = [];
		foreach ($this->subtitles->listForScreening($id) as $subtitle) {
			$subtitles[] = [
				'id' => $subtitle->getId(),
				'lang' => $subtitle->getLang(),
				'label' => $subtitle->getLabel(),
				'isDefault' => (bool)$subtitle->getIsDefault(),
				'src' => $route('subtitle', ['subtitleId' => $subtitle->getId()]),
			];
		}

		return [
			'ready' => $screening->getMediaStatus() === Screening::MEDIA_READY,
			'mode' => $hls ? 'hls' : 'mp4',
			'src' => $hls ? $route('file', ['file' => MediaStorage::MASTER_PLAYLIST]) : $route('mp4'),
			'durationS' => ($screening->getDurationMs() ?? 0) / 1000,
			'subtitles' => $subtitles,
		];
	}

	/**
	 * A file of the encrypted HLS package: a playlist, the key or a segment.
	 *
	 * @return array{content: string, type: string}
	 * @throws StreamDenied
	 */
	public function hlsFile(StreamContext $ctx, string $file): array {
		return $this->hlsContent($ctx->screening, $file, $ctx);
	}

	/**
	 * The same for the owner's own preview: no session, window or budget.
	 *
	 * @return array{content: string, type: string}
	 * @throws StreamDenied
	 */
	public function previewHlsFile(Screening $screening, string $file): array {
		return $this->hlsContent($screening, $file, null);
	}

	/**
	 * @param ?StreamContext $ctx a viewer's session (window and budget apply), or null for the owner
	 * @return array{content: string, type: string}
	 * @throws StreamDenied
	 */
	private function hlsContent(Screening $screening, string $file, ?StreamContext $ctx): array {
		if ($screening->servedAs() === Screening::MODE_MP4 || $screening->getMediaToken() === null) {
			throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
		}

		if (preg_match('/^v(\d+)_(\d{5})\.ts$/', $file, $m)) {
			$segments = $this->segmentStarts($screening, (int)$m[1]);
			$index = (int)$m[2];
			if (!isset($segments[$index])) {
				throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
			}
			[$start, $duration] = $segments[$index];
			if ($ctx !== null) {
				if (!PlaybackBudget::withinWindow($start, $ctx->session->getLastPositionS())) {
					throw new StreamDenied(StreamDenied::AHEAD);
				}
				$this->budget->consume($ctx->invite->getId(), $this->time->getTime(), $duration);
			}
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
	 * A byte range of a Direct MP4, at most one chunk long (mp4ChunkBytes).
	 *
	 * @throws StreamDenied
	 */
	public function mp4Range(StreamContext $ctx, ?string $rangeHeader): RangeResponse {
		return $this->mp4Content($ctx->screening, $rangeHeader, $ctx);
	}

	/**
	 * The owner's preview: same chunk size, no window or budget.
	 *
	 * @throws StreamDenied
	 */
	public function previewMp4Range(Screening $screening, ?string $rangeHeader): RangeResponse {
		return $this->mp4Content($screening, $rangeHeader, null);
	}

	/**
	 * @param ?StreamContext $ctx a viewer's session (window and budget apply), or null for the owner
	 * @throws StreamDenied
	 */
	private function mp4Content(Screening $screening, ?string $rangeHeader, ?StreamContext $ctx): RangeResponse {
		$file = $screening->servedAs() === Screening::MODE_MP4 ? $this->media->sourceFile($screening) : null;
		if ($file === null) {
			throw new StreamDenied(StreamDenied::NOT_FOUND, 404);
		}
		$size = $file->getSize();
		[$start, $end] = self::parseRange($rangeHeader, $size);
		$end = min($end, $start + self::MP4_CHUNK_BYTES - 1);

		$layout = $this->mp4Layout($file);
		$indexEnd = self::indexReadEnd($start, $end, $layout['moovOffset'], $layout['moovSize']);
		if ($indexEnd !== null) {
			$end = $indexEnd;
		} elseif ($ctx !== null) {
			// Map the byte offset to an approximate time to apply the playhead
			// window. Bitrates vary, so allow 50% slack on top of the window.
			$bytesPerSecond = $layout['durationS'] > 0 ? $size / $layout['durationS'] : $size;
			$end = min($end, $start + self::mp4ChunkBytes($bytesPerSecond) - 1);
			$approxTime = $start / $bytesPerSecond / 1.5;
			if (!PlaybackBudget::withinWindow($approxTime, $ctx->session->getLastPositionS())) {
				throw new StreamDenied(StreamDenied::AHEAD);
			}
			$this->budget->consume($ctx->invite->getId(), $this->time->getTime(), ($end - $start + 1) / $bytesPerSecond);
		}

		return new RangeResponse(fn () => MediaService::seekableStream($file), $start, $end, $size, 'video/mp4');
	}

	/**
	 * @throws StreamDenied
	 */
	public function subtitle(StreamContext $ctx, int $subtitleId): string {
		return $this->previewSubtitle($ctx->screening, $subtitleId);
	}

	/**
	 * A subtitle of this screening as WebVTT (the owner's preview uses it directly).
	 *
	 * @throws StreamDenied
	 */
	public function previewSubtitle(Screening $screening, int $subtitleId): string {
		try {
			$subtitle = $this->subtitles->get($subtitleId);
			if ($subtitle->getScreeningId() !== $screening->getId()) {
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
	/**
	 * Whether a range reads the MP4 index (moov), which the player needs
	 * before it can play anything and so is always allowed. Browsers read in
	 * aligned blocks: with the index at the end of a long film, Chrome asks
	 * for a range that starts up to 64 KB before it. A range starting within
	 * INDEX_MARGIN_BYTES before the index therefore counts too, and is cut off
	 * where the index ends, so it can't be stretched into a download.
	 *
	 * @return ?int the (possibly shortened) last byte, or null if this isn't an index read
	 */
	public static function indexReadEnd(int $start, int $end, int $moovOffset, int $moovSize): ?int {
		$moovEnd = $moovOffset + $moovSize - 1;
		if ($moovSize <= 0 || $start > $moovEnd || $start < $moovOffset - self::INDEX_MARGIN_BYTES) {
			return null;
		}
		if ($start < $moovOffset && $end < $moovOffset) {
			// Entirely before the index: plain film data.
			return null;
		}
		return min($end, $moovEnd);
	}

	/**
	 * How many bytes of film data one viewer request may get. Each chunk is
	 * charged to the playback budget by its duration, so a fixed size alone
	 * fails for low-bitrate films: at 160 kbit/s a 2 MB chunk is 105 s of
	 * film, and two of them overspend the 180 s budget. Capping by duration
	 * as well keeps every chunk at most MP4_CHUNK_SECONDS of budget (above
	 * the floor, which only matters for near-silent bitrates).
	 */
	public static function mp4ChunkBytes(float $bytesPerSecond): int {
		return (int)max(self::MP4_MIN_CHUNK_BYTES, min(self::MP4_CHUNK_BYTES, floor($bytesPerSecond * self::MP4_CHUNK_SECONDS)));
	}

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

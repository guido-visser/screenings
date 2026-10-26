<?php

declare(strict_types=1);

namespace OCA\Screenings\Controller;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Service\ScreeningService;
use OCA\Screenings\Stream\StreamDenied;
use OCA\Screenings\Stream\StreamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The owner watching their own screening (the notes review page). The
 * Nextcloud login and ownership authorize every request; nothing is tracked
 * and no playhead window or budget applies: it is the owner's own film.
 *
 * GET only and without the CSRF token, because hls.js and the browser's
 * media stack fetch these directly. Other users get 404.
 */
class PreviewController extends Controller {
	private const HLS_FILE = 'master\.m3u8|v\d+\.m3u8|v\d+_\d{5}\.ts|screenings-key';

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ScreeningService $screenings,
		private StreamService $stream,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}/preview', requirements: ['id' => '\d+'])]
	public function config(int $id): JSONResponse {
		try {
			return new JSONResponse($this->stream->previewConfig($this->owned($id)));
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}/preview/{file}', requirements: ['id' => '\d+', 'file' => self::HLS_FILE])]
	public function file(int $id, string $file): Response {
		try {
			$result = $this->stream->previewHlsFile($this->ready($id), $file);
		} catch (NotFoundException) {
			return $this->media(new JSONResponse([], Http::STATUS_NOT_FOUND));
		} catch (StreamDenied $e) {
			return $this->media(new JSONResponse(['status' => $e->reason], $e->status));
		}
		return $this->media(new DataDisplayResponse($result['content'], 200, ['Content-Type' => $result['type']]));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}/preview/video.mp4', requirements: ['id' => '\d+'])]
	public function mp4(int $id): Response {
		try {
			return $this->media($this->stream->previewMp4Range($this->ready($id), $this->request->getHeader('Range') ?: null));
		} catch (NotFoundException) {
			return $this->media(new JSONResponse([], Http::STATUS_NOT_FOUND));
		} catch (StreamDenied $e) {
			return $this->media(new JSONResponse(['status' => $e->reason], $e->status));
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}/preview/subtitles/{subtitleId}.vtt', requirements: ['id' => '\d+', 'subtitleId' => '\d+'])]
	public function subtitle(int $id, int $subtitleId): Response {
		try {
			$content = $this->stream->previewSubtitle($this->owned($id), $subtitleId);
		} catch (NotFoundException) {
			return $this->media(new JSONResponse([], Http::STATUS_NOT_FOUND));
		} catch (StreamDenied $e) {
			return $this->media(new JSONResponse(['status' => $e->reason], $e->status));
		}
		return $this->media(new DataDisplayResponse($content, 200, ['Content-Type' => 'text/vtt; charset=utf-8']));
	}

	/**
	 * @throws NotFoundException
	 */
	private function owned(int $id): Screening {
		$uid = $this->userSession->getUser()?->getUID() ?? throw new NotFoundException('Not logged in');
		return $this->screenings->get($id, $uid);
	}

	/**
	 * @throws NotFoundException|StreamDenied
	 */
	private function ready(int $id): Screening {
		$screening = $this->owned($id);
		if ($screening->getMediaStatus() !== Screening::MEDIA_READY) {
			throw new StreamDenied(StreamDenied::NOT_READY);
		}
		return $screening;
	}

	private function media(Response $response): Response {
		$response->addHeader('Cache-Control', 'no-store, private');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}
}

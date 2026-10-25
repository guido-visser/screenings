<?php

declare(strict_types=1);

namespace OCA\Screenings\Controller;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Stream\StreamDenied;
use OCA\Screenings\Stream\StreamGuard;
use OCA\Screenings\Stream\StreamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Media for an open viewer session. Every request is checked by
 * StreamGuard; see there for what is required.
 *
 * The CSRF token check is off because the player (and the browser's media
 * stack) fetch these directly. The session cookie is SameSite=Strict, which
 * already keeps other sites from using it.
 */
class StreamController extends Controller {
	private const HLS_FILE = 'master\.m3u8|v\d+\.m3u8|v\d+_\d{5}\.ts|screenings-key';

	public function __construct(
		IRequest $request,
		private StreamGuard $guard,
		private StreamService $stream,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/stream/{sessionId}/{file}', requirements: ['sessionId' => '\d+', 'file' => self::HLS_FILE])]
	public function file(int $sessionId, string $file): Response {
		try {
			$ctx = $this->guard->authorize($sessionId, $this->request, StreamGuard::AUTH_TOKEN);
			$result = $this->stream->hlsFile($ctx, $file);
		} catch (StreamDenied $e) {
			return $this->denied($e);
		}
		return $this->media(new DataDisplayResponse($result['content'], 200, ['Content-Type' => $result['type']]));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/stream/{sessionId}/video.mp4', requirements: ['sessionId' => '\d+'])]
	public function mp4(int $sessionId): Response {
		try {
			$ctx = $this->guard->authorize($sessionId, $this->request, StreamGuard::AUTH_SIGNATURE, 'mp4');
			$response = $this->stream->mp4Range($ctx, $this->request->getHeader('Range') ?: null);
		} catch (StreamDenied $e) {
			return $this->denied($e);
		}
		return $this->media($response);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/stream/{sessionId}/subtitles/{subtitleId}.vtt', requirements: ['sessionId' => '\d+', 'subtitleId' => '\d+'])]
	public function subtitle(int $sessionId, int $subtitleId): Response {
		try {
			$ctx = $this->guard->authorize($sessionId, $this->request, StreamGuard::AUTH_SIGNATURE, 'vtt:' . $subtitleId);
			$content = $this->stream->subtitle($ctx, $subtitleId);
		} catch (StreamDenied $e) {
			return $this->denied($e);
		}
		return $this->media(new DataDisplayResponse($content, 200, ['Content-Type' => 'text/vtt; charset=utf-8']));
	}

	/**
	 * Sent by the player every few seconds and on seeks. Needs only the
	 * session cookie; answers with a fresh playback token, or with why
	 * playback must stop.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/stream/{sessionId}/heartbeat', requirements: ['sessionId' => '\d+'])]
	public function heartbeat(int $sessionId): JSONResponse {
		try {
			$ctx = $this->guard->authorize($sessionId, $this->request, StreamGuard::AUTH_COOKIE);
		} catch (StreamDenied $e) {
			return new JSONResponse(['status' => $e->reason], $e->status);
		}
		$state = json_decode((string)file_get_contents('php://input'), true);
		return new JSONResponse($this->stream->heartbeat($ctx, $this->request, is_array($state) ? $state : []));
	}

	private function media(Response $response): Response {
		$response->addHeader('Cache-Control', 'no-store, private');
		$response->addHeader('Content-Disposition', 'inline');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}

	private function denied(StreamDenied $e): JSONResponse {
		$response = new JSONResponse(['status' => $e->reason], $e->status);
		$response->addHeader('Cache-Control', 'no-store, private');
		return $response;
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\Controller;

use Closure;
use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\Invite;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Service\InviteService;
use OCA\Screenings\Service\ScreeningService;
use OCA\Screenings\Service\SubtitleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * JSON API for the owner portal. Every call is scoped to the logged-in user's
 * own screenings; anything else answers 404.
 */
class ApiController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ScreeningService $screenings,
		private SubtitleService $subtitles,
		private InviteService $invites,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings')]
	public function listScreenings(): JSONResponse {
		return $this->handle(fn () => $this->screenings->listForOwner($this->uid()));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/screenings')]
	public function createScreening(): JSONResponse {
		return $this->handle(function () {
			$screening = $this->screenings->create($this->uid(), $this->body());
			return $this->screenings->toArray($screening);
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}', requirements: ['id' => '\d+'])]
	public function getScreening(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$screening = $this->screenings->get($id, $this->uid());
			return $this->screenings->toArray($screening) + [
				'subtitles' => $this->subtitles->listForScreening($id),
				'invites' => $this->invites->listForScreening($id),
			];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/screenings/{id}', requirements: ['id' => '\d+'])]
	public function updateScreening(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$screening = $this->screenings->update($id, $this->uid(), $this->body());
			return $this->screenings->toArray($screening);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/screenings/{id}', requirements: ['id' => '\d+'])]
	public function deleteScreening(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$this->screenings->delete($id, $this->uid());
			return [];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/screenings/{id}/open-link/regenerate', requirements: ['id' => '\d+'])]
	public function regenerateOpenLink(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			return $this->screenings->toArray($this->screenings->regenerateOpenLink($id, $this->uid()));
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/screenings/{id}/subtitles', requirements: ['id' => '\d+'])]
	public function uploadSubtitle(int $id, string $lang = '', string $label = ''): JSONResponse {
		return $this->handle(function () use ($id, $lang, $label) {
			$this->screenings->get($id, $this->uid());
			$file = $this->request->getUploadedFile('file');
			if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
				throw new ValidationException('Choose a subtitle file to upload', 'file');
			}
			$content = file_get_contents($file['tmp_name'], false, null, 0, SubtitleService::MAX_BYTES + 1);
			return $this->subtitles->add($id, (string)$file['name'], (string)$content, $lang, $label);
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/subtitles/{id}', requirements: ['id' => '\d+'])]
	public function updateSubtitle(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$subtitle = $this->subtitles->get($id);
			$this->screenings->get($subtitle->getScreeningId(), $this->uid());
			return $this->subtitles->update($subtitle, $this->body());
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/subtitles/{id}', requirements: ['id' => '\d+'])]
	public function deleteSubtitle(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$subtitle = $this->subtitles->get($id);
			$this->screenings->get($subtitle->getScreeningId(), $this->uid());
			$this->subtitles->delete($subtitle);
			return [];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/screenings/{id}/invites', requirements: ['id' => '\d+'])]
	public function createInvite(int $id, string $name = '', string $email = ''): JSONResponse {
		return $this->handle(function () use ($id, $name, $email) {
			$this->screenings->get($id, $this->uid());
			return $this->invites->create($id, $name, $email);
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/invites/{id}/revoke', requirements: ['id' => '\d+'])]
	public function revokeInvite(int $id): JSONResponse {
		return $this->handle(fn () => $this->invites->setRevoked($this->ownedInvite($id), true));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/invites/{id}/restore', requirements: ['id' => '\d+'])]
	public function restoreInvite(int $id): JSONResponse {
		return $this->handle(fn () => $this->invites->setRevoked($this->ownedInvite($id), false));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/invites/{id}', requirements: ['id' => '\d+'])]
	public function deleteInvite(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$this->invites->delete($this->ownedInvite($id));
			return [];
		});
	}

	/**
	 * @throws NotFoundException
	 */
	private function ownedInvite(int $id): Invite {
		$invite = $this->invites->get($id);
		$this->screenings->get($invite->getScreeningId(), $this->uid());
		return $invite;
	}

	private function uid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			// Unreachable behind the app framework's login check.
			throw new NotFoundException('Not logged in');
		}
		return $user->getUID();
	}

	/**
	 * The JSON request body. Unlike $this->request->getParams() this keeps
	 * explicit nulls and leaves out route and query parameters.
	 */
	private function body(): array {
		$decoded = json_decode((string)file_get_contents('php://input'), true);
		return is_array($decoded) ? $decoded : [];
	}

	private function handle(Closure $callback, int $successStatus = Http::STATUS_OK): JSONResponse {
		try {
			return new JSONResponse($callback(), $successStatus);
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'field' => $e->getField()], Http::STATUS_BAD_REQUEST);
		}
	}
}

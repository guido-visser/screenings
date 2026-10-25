<?php

declare(strict_types=1);

namespace OCA\Screenings\Controller;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Dashboard\DashboardService;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Service\InviteService;
use OCA\Screenings\Service\ScreeningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Viewing data for the owner's dashboard. Scoped to the logged-in user's
 * own screenings; anything else answers 404.
 */
class DashboardController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ScreeningService $screenings,
		private InviteService $invites,
		private DashboardService $dashboard,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}/dashboard', requirements: ['id' => '\d+'])]
	public function overview(int $id): JSONResponse {
		try {
			return new JSONResponse($this->dashboard->overview($this->screenings->get($id, $this->uid())));
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}/live', requirements: ['id' => '\d+'])]
	public function live(int $id): JSONResponse {
		try {
			return new JSONResponse($this->dashboard->live($this->screenings->get($id, $this->uid())));
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/invites/{id}/activity', requirements: ['id' => '\d+'])]
	public function viewer(int $id, ?int $before = null): JSONResponse {
		try {
			$invite = $this->invites->get($id);
			$screening = $this->screenings->get($invite->getScreeningId(), $this->uid());
			return new JSONResponse($this->dashboard->viewer($screening, $invite, $before));
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new NotFoundException('Not logged in');
	}
}

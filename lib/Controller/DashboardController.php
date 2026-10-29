<?php

declare(strict_types=1);

namespace OCA\Screenings\Controller;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Dashboard\DashboardService;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Service\FeedbackService;
use OCA\Screenings\Service\InviteService;
use OCA\Screenings\Service\QuestionService;
use OCA\Screenings\Service\ScreeningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
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
		private FeedbackService $feedback,
		private QuestionService $questions,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}/dashboard', requirements: ['id' => '\d+'])]
	public function overview(int $id, int $visitors = DashboardService::VISITORS_PAGE): JSONResponse {
		try {
			return new JSONResponse($this->dashboard->overview($this->screenings->get($id, $this->uid()), min(5000, max(1, $visitors))));
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

	/**
	 * Every note of the screening, with its viewers (review page).
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}/notes', requirements: ['id' => '\d+'])]
	public function notes(int $id): JSONResponse {
		try {
			return new JSONResponse($this->feedback->ownerNotes($this->screenings->get($id, $this->uid())));
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/notes/{id}', requirements: ['id' => '\d+'])]
	public function deleteNote(int $id): JSONResponse {
		try {
			$note = $this->feedback->getNote($id);
			$this->screenings->get($note->getScreeningId(), $this->uid());
			$this->feedback->deleteOwnerNote($note);
			return new JSONResponse([]);
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Downloads: notes.csv for a spreadsheet, notes.srt for an editing
	 * timeline. Plain links, so no CSRF token; they only read.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}/notes.{format}', requirements: ['id' => '\d+', 'format' => 'csv|srt'])]
	public function exportNotes(int $id, string $format): Response {
		try {
			$screening = $this->screenings->get($id, $this->uid());
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
		$data = $this->feedback->ownerNotes($screening);
		$csv = $format === 'csv';
		$response = new DataDownloadResponse(
			$csv ? FeedbackService::csv($data) : FeedbackService::srt($data),
			self::fileName($screening->getTitle()) . ' - notes.' . $format,
			$csv ? 'text/csv; charset=utf-8' : 'application/x-subrip; charset=utf-8',
		);
		$response->addHeader('Cache-Control', 'no-store, private');
		return $response;
	}

	/**
	 * Closing questions with a summary each, and every viewer's answers.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}/answers', requirements: ['id' => '\d+'])]
	public function answers(int $id): JSONResponse {
		try {
			return new JSONResponse($this->questions->ownerAnswers($this->screenings->get($id, $this->uid())));
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}/answers.csv', requirements: ['id' => '\d+'])]
	public function exportAnswers(int $id): Response {
		try {
			$screening = $this->screenings->get($id, $this->uid());
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
		$response = new DataDownloadResponse(
			QuestionService::csv($this->questions->ownerAnswers($screening)),
			self::fileName($screening->getTitle()) . ' - answers.csv',
			'text/csv; charset=utf-8',
		);
		$response->addHeader('Cache-Control', 'no-store, private');
		return $response;
	}

	private static function fileName(string $title): string {
		$name = trim((string)preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/u', ' ', $title));
		return $name === '' ? 'screening' : mb_substr($name, 0, 100);
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new NotFoundException('Not logged in');
	}
}

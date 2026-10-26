<?php

declare(strict_types=1);

namespace OCA\Screenings\Controller;

use Closure;
use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\Invite;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Service\InviteService;
use OCA\Screenings\Service\QuestionService;
use OCA\Screenings\Service\ScreeningService;
use OCA\Screenings\Service\SubtitleImporter;
use OCA\Screenings\Service\SubtitleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * JSON API for the owner portal. Every call is scoped to the logged-in user's
 * own screenings; anything else answers 404. Routes that start media
 * processing or create invites carry a per-user rate limit, so a hijacked
 * account can't tie up the server; invitation mails have their own limit in
 * InviteService.
 */
class ApiController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ScreeningService $screenings,
		private SubtitleService $subtitles,
		private SubtitleImporter $subtitleImporter,
		private InviteService $invites,
		private QuestionService $questionService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings')]
	public function listScreenings(): JSONResponse {
		return $this->handle(fn () => $this->screenings->listForOwner($this->uid()));
	}

	/**
	 * Used by the Files app to offer "Go to screening" instead of "Create screening".
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/by-source')]
	public function screeningsBySource(): JSONResponse {
		// A JSON object even when empty, keyed by file id.
		return $this->handle(fn () => (object)$this->screenings->idsBySource($this->uid()));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/screenings')]
	public function createScreening(): JSONResponse {
		return $this->handle(function () {
			$screening = $this->screenings->create($this->uid(), $this->body());
			// Subtitles next to the video may have been added.
			return $this->screenings->toArray($screening) + ['subtitles' => $this->subtitles->listForScreening($screening->getId())];
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
			// A new video may have brought subtitles from its folder.
			return $this->screenings->toArray($screening) + ['subtitles' => $this->subtitles->listForScreening($id)];
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
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/screenings/{id}/media/reprocess', requirements: ['id' => '\d+'])]
	public function reprocessMedia(int $id): JSONResponse {
		return $this->handle(fn () => $this->screenings->toArray($this->screenings->reprocessMedia($id, $this->uid())));
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

	/**
	 * Adds the subtitle files next to the screening's video ("film.en.srt").
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/screenings/{id}/subtitles/import', requirements: ['id' => '\d+'])]
	public function importSubtitles(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$screening = $this->screenings->get($id, $this->uid());
			return $this->subtitleImporter->importSiblings($screening) + [
				'subtitles' => $this->subtitles->listForScreening($id),
			];
		});
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
	#[UserRateLimit(limit: 300, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/screenings/{id}/invites', requirements: ['id' => '\d+'])]
	public function createInvite(int $id, string $name = '', string $email = ''): JSONResponse {
		return $this->handle(function () use ($id, $name, $email) {
			$screening = $this->screenings->get($id, $this->uid());
			$body = $this->body();
			$invite = $this->invites->create($id, $name, $email);
			if (!empty($body['allowNotes']) || !empty($body['askQuestions'])) {
				$invite = $this->invites->setFeedback($invite, !empty($body['allowNotes']), !empty($body['askQuestions']));
			}
			if (!empty($body['sendEmail'])) {
				try {
					$invite = $this->invites->sendInvitation($screening, $invite, (string)($body['message'] ?? ''));
				} catch (ValidationException $e) {
					// The invite exists either way; report the mail problem alongside it.
					return $invite->jsonSerialize() + ['mailError' => $e->getMessage()];
				}
			}
			return $invite;
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/invites/{id}/send', requirements: ['id' => '\d+'])]
	public function sendInvite(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$invite = $this->ownedInvite($id);
			$screening = $this->screenings->get($invite->getScreeningId(), $this->uid());
			return $this->invites->sendInvitation($screening, $invite, (string)($this->body()['message'] ?? ''));
		});
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
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/screenings/{id}/questions', requirements: ['id' => '\d+'])]
	public function questions(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$this->screenings->get($id, $this->uid());
			return $this->questionService->listForScreening($id);
		});
	}

	/**
	 * Replaces the closing questions: {questions: [{id?, kind, label, options?, required?}]}.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/screenings/{id}/questions', requirements: ['id' => '\d+'])]
	public function saveQuestions(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$this->screenings->get($id, $this->uid());
			return $this->questionService->save($id, $this->body()['questions'] ?? null);
		});
	}

	/**
	 * Notes and closing questions for one invite: {allowNotes?, askQuestions?}.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/invites/{id}/feedback', requirements: ['id' => '\d+'])]
	public function inviteFeedback(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			[$notes, $questions] = $this->feedbackSwitches();
			return $this->invites->setFeedback($this->ownedInvite($id), $notes, $questions);
		});
	}

	/**
	 * The same for every personal invite of the screening.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/screenings/{id}/invites/feedback', requirements: ['id' => '\d+'])]
	public function allInvitesFeedback(int $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$this->screenings->get($id, $this->uid());
			[$notes, $questions] = $this->feedbackSwitches();
			return $this->invites->setFeedbackForAll($id, $notes, $questions);
		});
	}

	/**
	 * @return array{?bool, ?bool}
	 */
	private function feedbackSwitches(): array {
		$body = $this->body();
		$read = fn (string $key) => array_key_exists($key, $body) && $body[$key] !== null ? (bool)$body[$key] : null;
		return [$read('allowNotes'), $read('askQuestions')];
	}

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

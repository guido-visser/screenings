<?php

declare(strict_types=1);

namespace OCA\Screenings\Controller;

use Closure;
use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Service\FeedbackService;
use OCA\Screenings\Service\QuestionService;
use OCA\Screenings\Stream\StreamContext;
use OCA\Screenings\Stream\StreamDenied;
use OCA\Screenings\Stream\StreamGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The viewer's own notes and answers to closing questions, for an open
 * viewer session.
 *
 * Like the heartbeat, these routes rely on the SameSite=Strict session
 * cookie instead of the CSRF token: other sites can't send it.
 */
class FeedbackController extends Controller {
	public function __construct(
		IRequest $request,
		private StreamGuard $guard,
		private FeedbackService $feedback,
		private QuestionService $questionService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/stream/{sessionId}/notes', requirements: ['sessionId' => '\d+'])]
	public function notes(int $sessionId): JSONResponse {
		return $this->handle($sessionId, fn (StreamContext $ctx) => $this->feedback->viewerNotes($ctx));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/stream/{sessionId}/notes', requirements: ['sessionId' => '\d+'])]
	public function addNote(int $sessionId): JSONResponse {
		$body = $this->body();
		return $this->handle($sessionId, fn (StreamContext $ctx) => $this->feedback->addNote($ctx, $body['position'] ?? null, $body['body'] ?? null), Http::STATUS_CREATED);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/stream/{sessionId}/notes/{noteId}', requirements: ['sessionId' => '\d+', 'noteId' => '\d+'])]
	public function updateNote(int $sessionId, int $noteId): JSONResponse {
		$body = $this->body();
		return $this->handle($sessionId, fn (StreamContext $ctx) => $this->feedback->updateNote($ctx, $noteId, $body['body'] ?? null));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/stream/{sessionId}/notes/{noteId}', requirements: ['sessionId' => '\d+', 'noteId' => '\d+'])]
	public function deleteNote(int $sessionId, int $noteId): JSONResponse {
		return $this->handle($sessionId, function (StreamContext $ctx) use ($noteId) {
			$this->feedback->deleteNote($ctx, $noteId);
			return [];
		});
	}

	/**
	 * The closing questions with this viewer's answers so far.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/stream/{sessionId}/questions', requirements: ['sessionId' => '\d+'])]
	public function questions(int $sessionId): JSONResponse {
		return $this->handle($sessionId, fn (StreamContext $ctx) => $this->questionService->viewerQuestions($ctx));
	}

	/**
	 * All answers at once: {answers: {questionId: value}}.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/stream/{sessionId}/answers', requirements: ['sessionId' => '\d+'])]
	public function answers(int $sessionId): JSONResponse {
		$body = $this->body();
		return $this->handle($sessionId, fn (StreamContext $ctx) => $this->questionService->saveAnswers($ctx, $body['answers'] ?? null));
	}

	private function body(): array {
		$decoded = json_decode((string)file_get_contents('php://input'), true);
		return is_array($decoded) ? $decoded : [];
	}

	private function handle(int $sessionId, Closure $callback, int $successStatus = Http::STATUS_OK): JSONResponse {
		try {
			$ctx = $this->guard->authorize($sessionId, $this->request, StreamGuard::AUTH_COOKIE);
			return new JSONResponse($callback($ctx), $successStatus);
		} catch (StreamDenied $e) {
			return new JSONResponse(['status' => $e->reason], $e->status);
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'field' => $e->getField()], Http::STATUS_BAD_REQUEST);
		}
	}
}

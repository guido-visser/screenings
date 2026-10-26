<?php

declare(strict_types=1);

namespace OCA\Screenings\Controller;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Mail\MailTemplateService;
use OCA\Screenings\Service\MailService;
use OCA\Screenings\Service\ScreeningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The invitation email designer: the owner's default design, or one
 * screening's own (?screeningId= / "screeningId" in the body). Screenings
 * are only reachable for their owner; anything else answers 404.
 */
class MailTemplateController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ScreeningService $screenings,
		private MailTemplateService $templates,
		private MailService $mail,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/mail-template')]
	public function get(?int $screeningId = null): JSONResponse {
		return $this->handle(function () use ($screeningId) {
			$design = $this->templates->forEditor($this->uid(), $this->screening($screeningId));
			return $design + [
				'builtin' => MailTemplateService::builtin(),
				'variables' => array_map(fn (string $name, string $description) => ['name' => $name, 'description' => $description],
					array_keys(MailTemplateService::VARIABLES), MailTemplateService::VARIABLES),
			];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/mail-template')]
	public function save(): JSONResponse {
		return $this->handle(function () {
			$body = $this->body();
			$screening = $this->screening(isset($body['screeningId']) ? (int)$body['screeningId'] : null);
			$this->templates->save($this->uid(), $screening, (string)($body['subject'] ?? ''), (string)($body['html'] ?? ''));
			return $this->templates->forEditor($this->uid(), $screening);
		});
	}

	/**
	 * A screening goes back to the default design, the default to the built-in one.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/mail-template')]
	public function reset(?int $screeningId = null): JSONResponse {
		return $this->handle(function () use ($screeningId) {
			$screening = $this->screening($screeningId);
			$this->templates->reset($this->uid(), $screening);
			return $this->templates->forEditor($this->uid(), $screening);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/mail-template/preview')]
	public function preview(): JSONResponse {
		return $this->handle(function () {
			[$screening, $subject, $html] = $this->draft();
			return $this->mail->preview($this->uid(), $screening, $subject, $html)
				+ ['unknown' => MailTemplateService::unknownNames($subject, $html)];
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/mail-template/test')]
	public function test(): JSONResponse {
		return $this->handle(function () {
			[$screening, $subject, $html] = $this->draft();
			return ['sentTo' => $this->mail->sendTest($this->uid(), $screening, $subject, $html)];
		});
	}

	/**
	 * @return array{?Screening, string, string}
	 * @throws NotFoundException
	 */
	private function draft(): array {
		$body = $this->body();
		return [
			$this->screening(isset($body['screeningId']) ? (int)$body['screeningId'] : null),
			(string)($body['subject'] ?? ''),
			(string)($body['html'] ?? ''),
		];
	}

	/**
	 * @throws NotFoundException
	 */
	private function screening(?int $id): ?Screening {
		return $id === null ? null : $this->screenings->get($id, $this->uid());
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new NotFoundException('Not logged in');
	}

	private function body(): array {
		$decoded = json_decode((string)file_get_contents('php://input'), true);
		return is_array($decoded) ? $decoded : [];
	}

	private function handle(\Closure $callback): JSONResponse {
		try {
			return new JSONResponse($callback());
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'field' => $e->getField()], Http::STATUS_BAD_REQUEST);
		}
	}
}

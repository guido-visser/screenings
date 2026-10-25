<?php

declare(strict_types=1);

namespace OCA\Screenings\Controller;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Service\AccessService;
use OCA\Screenings\Service\EventLogger;
use OCA\Screenings\Service\MailService;
use OCA\Screenings\Service\OtpService;
use OCA\Screenings\Service\SessionService;
use OCA\Screenings\Stream\StreamContext;
use OCA\Screenings\Stream\StreamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Util;

/**
 * The viewer-facing screening page, reached through a personal invite link
 * or a screening's open link.
 *
 * The page renders one of these states (passed to the viewer bundle):
 *   unavailable  unknown, deleted or disabled link
 *   revoked      the owner revoked this invite
 *   expired      the screening's expiry date has passed
 *   email        open link: ask for name and email, then a mailed code
 *   password     the screening has a password this browser hasn't entered
 *   not_ready    access is fine but the video is not processed yet
 *   ready        a viewer session was started
 */
class PublicController extends Controller {
	private const TOKEN = '[A-Za-z0-9]{16,64}';

	public function __construct(
		IRequest $request,
		private AccessService $access,
		private SessionService $sessions,
		private OtpService $otp,
		private MailService $mail,
		private EventLogger $events,
		private StreamService $stream,
		private IInitialState $initialState,
		private IUserManager $userManager,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	// Unknown tokens slow down further guesses from the same IP.
	#[BruteForceProtection(action: 'screenings_link')]
	#[FrontpageRoute(verb: 'GET', url: '/s/{token}', requirements: ['token' => self::TOKEN])]
	public function show(string $token): TemplateResponse {
		$response = new TemplateResponse(Application::APP_ID, 'viewer', [], TemplateResponse::RENDER_AS_BASE);
		$resolved = $this->access->resolve($token);
		if ($resolved === null) {
			$response->setStatus(Http::STATUS_NOT_FOUND);
			$response->throttle(['token' => $token]);
			return $this->render($response, $token, 'unavailable');
		}

		['invite' => $invite, 'screening' => $screening] = $resolved;
		$this->events->log($screening->getId(), $invite?->getId(), null, EventLogger::PAGE_OPEN, array_filter([
			'link' => $invite === null ? 'open' : 'invite',
			'referrer' => $this->request->getHeader('Referer') ?: null,
		]));

		$denial = $this->access->denial($invite, $screening);
		if ($denial !== null) {
			$this->events->log($screening->getId(), $invite?->getId(), null, EventLogger::ACCESS_DENIED, ['reason' => $denial]);
			$response->setStatus(Http::STATUS_FORBIDDEN);
			return $this->render($response, $token, $denial, $denial === AccessService::DENIED_EXPIRED ? $screening : null);
		}

		if ($invite === null) {
			return $this->render($response, $token, 'email', $screening);
		}
		if ($this->access->needsPassword($invite, $screening, $this->request)) {
			return $this->render($response, $token, 'password', $screening, $invite);
		}
		if ($screening->getMediaStatus() !== Screening::MEDIA_READY) {
			return $this->render($response, $token, 'not_ready', $screening, $invite);
		}

		$session = $this->sessions->start($invite, $this->request, $response);
		$this->events->log($screening->getId(), $invite->getId(), $session->getId(), EventLogger::SESSION_START);

		// hls.js feeds media through blob: URLs and may decode in a blob: worker.
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedMediaDomain('blob:');
		$policy->addAllowedWorkerSrcDomain('blob:');
		$response->setContentSecurityPolicy($policy);

		$playback = $this->stream->playerConfig(new StreamContext($session, $invite, $screening), $this->request);
		return $this->render($response, $token, 'ready', $screening, $invite, $playback);
	}

	#[PublicPage]
	#[BruteForceProtection(action: 'screenings_password')]
	#[FrontpageRoute(verb: 'POST', url: '/s/{token}/password', requirements: ['token' => self::TOKEN])]
	public function unlock(string $token, string $password = ''): JSONResponse {
		$resolved = $this->access->resolve($token);
		if ($resolved === null || $resolved['invite'] === null
			|| $this->access->denial($resolved['invite'], $resolved['screening']) !== null) {
			return new JSONResponse(['message' => $this->l->t('This link is not available')], Http::STATUS_NOT_FOUND);
		}
		['invite' => $invite, 'screening' => $screening] = $resolved;

		$response = new JSONResponse(['ok' => true]);
		if (!$this->access->unlock($invite, $screening, $password, $response)) {
			$this->events->log($screening->getId(), $invite->getId(), null, EventLogger::PASSWORD_FAIL);
			$failed = new JSONResponse(['message' => $this->l->t('Wrong password')], Http::STATUS_FORBIDDEN);
			$failed->throttle(['token' => $token]);
			return $failed;
		}
		$this->events->log($screening->getId(), $invite->getId(), null, EventLogger::PASSWORD_OK);
		return $response;
	}

	#[PublicPage]
	// Per IP; OtpService also limits codes per email address. Generous enough
	// for several colleagues behind one office IP.
	#[AnonRateLimit(limit: 10, period: 900)]
	#[FrontpageRoute(verb: 'POST', url: '/s/{token}/code', requirements: ['token' => self::TOKEN])]
	public function requestCode(string $token, string $name = '', string $email = ''): JSONResponse {
		$screening = $this->openLinkScreening($token);
		if ($screening === null) {
			return new JSONResponse(['message' => $this->l->t('This link is not available')], Http::STATUS_NOT_FOUND);
		}
		try {
			$this->otp->request($screening, $name, $email);
		} catch (ValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'field' => $e->getField()], Http::STATUS_BAD_REQUEST);
		}
		$this->events->log($screening->getId(), null, null, EventLogger::OTP_REQUESTED, [
			'email' => OtpService::normalizeEmail($email),
			'name' => trim($name),
		]);
		return new JSONResponse(['sent' => true]);
	}

	#[PublicPage]
	#[BruteForceProtection(action: 'screenings_code')]
	#[FrontpageRoute(verb: 'POST', url: '/s/{token}/code/verify', requirements: ['token' => self::TOKEN])]
	public function verifyCode(string $token, string $email = '', string $code = ''): JSONResponse {
		$screening = $this->openLinkScreening($token);
		if ($screening === null) {
			return new JSONResponse(['message' => $this->l->t('This link is not available')], Http::STATUS_NOT_FOUND);
		}
		try {
			$invite = $this->otp->verify($screening, $email, $code);
		} catch (ValidationException $e) {
			$this->events->log($screening->getId(), null, null, EventLogger::OTP_FAIL, ['email' => OtpService::normalizeEmail($email)]);
			$failed = new JSONResponse(['message' => $e->getMessage(), 'field' => $e->getField()], Http::STATUS_FORBIDDEN);
			$failed->throttle(['token' => $token]);
			return $failed;
		}
		$this->events->log($screening->getId(), $invite->getId(), null, EventLogger::OTP_VERIFIED);
		if ($invite->isRevoked()) {
			return new JSONResponse(['message' => $this->l->t('Your access to this screening was revoked')], Http::STATUS_FORBIDDEN);
		}
		// The viewer continues on their own personal link.
		return new JSONResponse(['url' => $this->mail->publicLink($invite->getToken())]);
	}

	private function openLinkScreening(string $token): ?Screening {
		$resolved = $this->access->resolve($token);
		if ($resolved === null || $resolved['invite'] !== null
			|| $this->access->denial(null, $resolved['screening']) !== null) {
			return null;
		}
		return $resolved['screening'];
	}

	private function render(TemplateResponse $response, string $token, string $state, ?Screening $screening = null, ?Invite $invite = null, ?array $playback = null): TemplateResponse {
		$this->initialState->provideInitialState('viewer', [
			'state' => $state,
			'token' => $token,
			'playback' => $playback,
			'screening' => $screening === null ? null : [
				'title' => $screening->getTitle(),
				'description' => $screening->getDescription(),
				'owner' => $this->userManager->getDisplayName($screening->getOwnerUid()) ?? $screening->getOwnerUid(),
				'expiresAt' => $screening->getExpiresAt(),
				'durationMs' => $screening->getDurationMs(),
				'watermark' => (bool)$screening->getWatermark(),
			],
			'viewer' => $invite === null ? null : [
				'name' => $invite->getName(),
				'email' => $invite->getEmail(),
			],
		]);
		Util::addScript(Application::APP_ID, Application::APP_ID . '-viewer');
		return $response;
	}
}

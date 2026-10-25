<?php

declare(strict_types=1);

namespace OCA\Screenings\Controller;

use OCA\Screenings\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

/**
 * The viewer-facing screening page reached through an invite or open link.
 *
 * Phase 1 stub: renders the viewer shell. Token lookup, password, OTP and
 * sessions are added in phase 4.
 */
class PublicController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/s/{token}', requirements: ['token' => '[A-Za-z0-9]{16,64}'])]
	public function show(string $token): TemplateResponse {
		$this->initialState->provideInitialState('token', $token);
		Util::addScript(Application::APP_ID, Application::APP_ID . '-viewer');
		return new TemplateResponse(Application::APP_ID, 'viewer', [], TemplateResponse::RENDER_AS_BASE);
	}
}

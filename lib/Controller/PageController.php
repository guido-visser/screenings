<?php

declare(strict_types=1);

namespace OCA\Screenings\Controller;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Service\DeliveryModes;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

/**
 * The owner-facing portal (screenings, invites, dashboards).
 */
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private DeliveryModes $deliveryModes,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		$this->initialState->provideInitialState('deliveryModes', $this->deliveryModes->available());
		Util::addScript(Application::APP_ID, Application::APP_ID . '-portal');
		return new TemplateResponse(Application::APP_ID, 'portal');
	}
}

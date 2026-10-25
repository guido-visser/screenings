<?php

declare(strict_types=1);

namespace OCA\Screenings\Controller;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Service\AdminSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only (no #[NoAdminRequired]): server-wide media settings.
 */
class AdminController extends Controller {
	public function __construct(
		IRequest $request,
		private AdminSettingsService $settings,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/admin/settings')]
	public function get(): JSONResponse {
		return new JSONResponse($this->settings->get());
	}

	#[FrontpageRoute(verb: 'POST', url: '/api/admin/geoip/download')]
	public function downloadGeoIp(): JSONResponse {
		try {
			return new JSONResponse($this->settings->downloadGeoIp());
		} catch (ValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[FrontpageRoute(verb: 'PUT', url: '/api/admin/settings')]
	public function update(): JSONResponse {
		$data = json_decode((string)file_get_contents('php://input'), true);
		try {
			return new JSONResponse($this->settings->update(is_array($data) ? $data : []));
		} catch (ValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'field' => $e->getField()], Http::STATUS_BAD_REQUEST);
		}
	}
}

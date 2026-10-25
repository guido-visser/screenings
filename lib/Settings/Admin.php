<?php

declare(strict_types=1);

namespace OCA\Screenings\Settings;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Service\AdminSettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private AdminSettingsService $settings,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('adminSettings', $this->settings->get());
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}

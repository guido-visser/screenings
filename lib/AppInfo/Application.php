<?php

declare(strict_types=1);

namespace OCA\Screenings\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Screenings\Listener\LoadFilesScriptsListener;
use OCA\Screenings\Listener\SubtitleFileListener;
use OCA\Screenings\Listener\UserDeletedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'screenings';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		self::registerVendorAutoloader();
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptsListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		foreach ([NodeCreatedEvent::class, NodeWrittenEvent::class, NodeRenamedEvent::class, NodeCopiedEvent::class] as $event) {
			$context->registerEventListener($event, SubtitleFileListener::class);
		}
	}

	/**
	 * The app's only runtime library (MaxMind's database reader, for GeoIP).
	 * Registered by namespace instead of loading vendor/autoload.php, which
	 * in development also maps dev-only packages.
	 */
	private static function registerVendorAutoloader(): void {
		$base = __DIR__ . '/../../vendor/maxmind-db/reader/src/MaxMind/Db/';
		spl_autoload_register(static function (string $class) use ($base): void {
			if (str_starts_with($class, 'MaxMind\\Db\\')) {
				$file = $base . str_replace('\\', '/', substr($class, strlen('MaxMind\\Db\\'))) . '.php';
				if (is_file($file)) {
					require_once $file;
				}
			}
		});
	}

	public function boot(IBootContext $context): void {
	}
}

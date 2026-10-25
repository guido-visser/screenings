<?php

declare(strict_types=1);

namespace OCA\Screenings\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Media pipeline state: a token per processing run (stale background jobs
 * compare it and give up) and a non-fatal warning shown in the portal.
 */
class Version1001Date20261026000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('scr_screenings');
		$changed = false;

		if (!$table->hasColumn('media_token')) {
			$table->addColumn('media_token', Types::STRING, ['notnull' => false, 'length' => 32]);
			$changed = true;
		}
		if (!$table->hasColumn('media_warning')) {
			$table->addColumn('media_warning', Types::TEXT, ['notnull' => false]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}

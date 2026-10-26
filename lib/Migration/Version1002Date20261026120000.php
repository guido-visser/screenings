<?php

declare(strict_types=1);

namespace OCA\Screenings\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The tracking notice viewers see: on or off per screening, with an
 * optional custom text (null means the default text).
 */
class Version1002Date20261026120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('scr_screenings');
		$changed = false;

		if (!$table->hasColumn('tracking_notice_enabled')) {
			// Existing screenings keep showing a notice.
			$table->addColumn('tracking_notice_enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$changed = true;
		}
		if (!$table->hasColumn('tracking_notice')) {
			$table->addColumn('tracking_notice', Types::TEXT, ['notnull' => false]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * What the "Auto" delivery mode chose for the current source: play it
 * directly, or one of the transcoder's plans.
 */
class Version1003Date20261026150000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('scr_screenings');
		if ($table->hasColumn('media_mode')) {
			return null;
		}
		$table->addColumn('media_mode', Types::STRING, ['notnull' => false, 'length' => 16]);
		return $schema;
	}
}

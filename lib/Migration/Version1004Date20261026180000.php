<?php

declare(strict_types=1);

namespace OCA\Screenings\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The file a subtitle was imported from (next to the video), so a new
 * version of that file replaces the subtitle. Null for uploaded ones.
 */
class Version1004Date20261026180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('scr_subtitles');
		if ($table->hasColumn('source_file_id')) {
			return null;
		}
		$table->addColumn('source_file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		return $schema;
	}
}

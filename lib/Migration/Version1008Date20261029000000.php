<?php

declare(strict_types=1);

namespace OCA\Screenings\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Public screenings: a link anyone can watch without giving a name or email
 * address. Each visitor becomes an anonymous invite (source "public").
 */
class Version1008Date20261029000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('scr_screenings');
		if ($table->hasColumn('public_link_enabled')) {
			return null;
		}
		$table->addColumn('public_link_enabled', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('public_link_token', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addUniqueIndex(['public_link_token'], 'scr_screenings_public');
		return $schema;
	}
}

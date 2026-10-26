<?php

declare(strict_types=1);

namespace OCA\Screenings\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Invitation email designs: an owner's default (screening_id null) and
 * optional per-screening designs.
 */
class Version1005Date20261026200000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('scr_mail_templates')) {
			return null;
		}
		$table = $schema->createTable('scr_mail_templates');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('screening_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('html', Types::TEXT, ['notnull' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['owner_uid'], 'scr_mailtpl_owner');
		$table->addIndex(['screening_id'], 'scr_mailtpl_scr');
		return $schema;
	}
}

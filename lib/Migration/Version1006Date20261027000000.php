<?php

declare(strict_types=1);

namespace OCA\Screenings\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Viewer feedback: per-invite switches for notes and closing questions, and
 * the notes viewers leave at a moment in the film.
 */
class Version1006Date20261027000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		$invites = $schema->getTable('scr_invites');
		if (!$invites->hasColumn('allow_notes')) {
			$invites->addColumn('allow_notes', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$invites->addColumn('ask_questions', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$changed = true;
		}

		if (!$schema->hasTable('scr_notes')) {
			$table = $schema->createTable('scr_notes');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('screening_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('invite_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('session_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('position_s', Types::FLOAT, ['notnull' => true]);
			$table->addColumn('body', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['screening_id', 'position_s'], 'scr_notes_scr_pos');
			$table->addIndex(['invite_id'], 'scr_notes_invite');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}

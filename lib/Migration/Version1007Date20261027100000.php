<?php

declare(strict_types=1);

namespace OCA\Screenings\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Closing questions per screening and the viewers' answers (one per invite
 * and question, changeable).
 */
class Version1007Date20261027100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('scr_questions')) {
			$table = $schema->createTable('scr_questions');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('screening_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('sort', Types::SMALLINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('options', Types::TEXT, ['notnull' => false]);
			$table->addColumn('required', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['screening_id'], 'scr_questions_scr');
			$changed = true;
		}

		if (!$schema->hasTable('scr_answers')) {
			$table = $schema->createTable('scr_answers');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('screening_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('invite_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('question_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('value', Types::TEXT, ['notnull' => true]);
			$table->addColumn('answered_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['invite_id', 'question_id'], 'scr_answers_inv_q');
			$table->addIndex(['screening_id'], 'scr_answers_scr');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}

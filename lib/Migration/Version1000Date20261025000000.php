<?php

declare(strict_types=1);

namespace OCA\Screenings\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema: screenings, subtitles, invites, OTP codes, viewer sessions,
 * tracking events and watched ranges.
 *
 * Timestamps are unix seconds unless the column name ends in _ms.
 */
class Version1000Date20261025000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('scr_screenings')) {
			$table = $schema->createTable('scr_screenings');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('source_file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('delivery_mode', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'mp4']);
			$table->addColumn('media_status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'none']);
			$table->addColumn('media_progress', Types::SMALLINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('media_error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('duration_ms', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('password_hash', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('expires_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('watermark', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('open_link_token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('open_link_enabled', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('retention_days', Types::INTEGER, ['notnull' => true, 'default' => 365, 'unsigned' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['owner_uid'], 'scr_screenings_owner');
			$table->addUniqueIndex(['open_link_token'], 'scr_screenings_otoken');
		}

		if (!$schema->hasTable('scr_subtitles')) {
			$table = $schema->createTable('scr_subtitles');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('screening_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('lang', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('is_default', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['screening_id'], 'scr_subtitles_scr');
		}

		if (!$schema->hasTable('scr_invites')) {
			$table = $schema->createTable('scr_invites');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('screening_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('email', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'invited']);
			$table->addColumn('email_verified_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('invited_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('revoked_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['screening_id'], 'scr_invites_scr');
			$table->addUniqueIndex(['token'], 'scr_invites_token');
		}

		if (!$schema->hasTable('scr_otp')) {
			$table = $schema->createTable('scr_otp');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('screening_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('email', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('code_hash', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('attempts', Types::SMALLINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['screening_id', 'email'], 'scr_otp_scr_email');
		}

		if (!$schema->hasTable('scr_sessions')) {
			$table = $schema->createTable('scr_sessions');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('invite_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('secret_hash', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('started_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('last_heartbeat_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('ended_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('ip', Types::STRING, ['notnull' => false, 'length' => 45]);
			$table->addColumn('user_agent', Types::STRING, ['notnull' => false, 'length' => 512]);
			$table->addColumn('browser', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('os', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('device_type', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('screen_w', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('screen_h', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('country', Types::STRING, ['notnull' => false, 'length' => 2]);
			$table->addColumn('city', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('last_position_s', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('playing', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('current_subtitle', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('current_quality', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['invite_id'], 'scr_sessions_invite');
			$table->addIndex(['last_heartbeat_at'], 'scr_sessions_hb');
		}

		if (!$schema->hasTable('scr_events')) {
			$table = $schema->createTable('scr_events');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			// Null for events before a session exists (e.g. page_open, password_fail).
			$table->addColumn('session_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('invite_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('screening_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('position_s', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('payload', Types::TEXT, ['notnull' => false]);
			$table->addColumn('client_ts_ms', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('server_ts_ms', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['session_id'], 'scr_events_session');
			$table->addIndex(['invite_id'], 'scr_events_invite');
			$table->addIndex(['screening_id', 'server_ts_ms'], 'scr_events_scr_ts');
		}

		if (!$schema->hasTable('scr_watch_ranges')) {
			$table = $schema->createTable('scr_watch_ranges');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('session_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('start_s', Types::FLOAT, ['notnull' => true]);
			$table->addColumn('end_s', Types::FLOAT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['session_id'], 'scr_ranges_session');
		}

		return $schema;
	}
}

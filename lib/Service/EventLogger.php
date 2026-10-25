<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Writes one row to the tracking log. Phase 6 adds the player events; the
 * access events (page opens, password and code attempts) start here.
 */
class EventLogger {
	public const PAGE_OPEN = 'page_open';
	public const PASSWORD_OK = 'password_ok';
	public const PASSWORD_FAIL = 'password_fail';
	public const OTP_REQUESTED = 'otp_requested';
	public const OTP_VERIFIED = 'otp_verified';
	public const OTP_FAIL = 'otp_fail';
	public const ACCESS_DENIED = 'access_denied';
	public const SESSION_START = 'session_start';

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	public function log(int $screeningId, ?int $inviteId, ?int $sessionId, string $type, array $payload = [], ?float $positionS = null, ?int $clientTsMs = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('scr_events')
			->values([
				'screening_id' => $qb->createNamedParameter($screeningId, IQueryBuilder::PARAM_INT),
				'invite_id' => $qb->createNamedParameter($inviteId, $inviteId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
				'session_id' => $qb->createNamedParameter($sessionId, $sessionId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
				'type' => $qb->createNamedParameter($type),
				'position_s' => $qb->createNamedParameter($positionS, $positionS === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
				'payload' => $qb->createNamedParameter($payload === [] ? null : json_encode($payload), $payload === [] ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
				'client_ts_ms' => $qb->createNamedParameter($clientTsMs, $clientTsMs === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
				'server_ts_ms' => $qb->createNamedParameter((int)$this->time->now()->format('Uv'), IQueryBuilder::PARAM_INT),
			])
			->executeStatement();
	}
}

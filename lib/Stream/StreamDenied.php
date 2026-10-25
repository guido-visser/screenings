<?php

declare(strict_types=1);

namespace OCA\Screenings\Stream;

/**
 * A media request was refused. The reason goes to the player (which shows a
 * matching message); the response body never says more than that.
 */
class StreamDenied extends \Exception {
	public const NOT_FOUND = 'not_found';
	/** The owner revoked this invite. */
	public const REVOKED = 'revoked';
	/** The screening's expiry date passed. */
	public const EXPIRED = 'expired';
	/** The same invite opened the screening elsewhere, which ended this session. */
	public const REPLACED = 'replaced';
	/** No heartbeat recently: the page isn't open (or JS is blocked). */
	public const STALE = 'stale';
	/** Missing, expired or foreign playback token / signature. */
	public const TOKEN = 'token';
	/** Requested too far ahead of the playhead, or too fast. */
	public const AHEAD = 'ahead';
	public const NOT_READY = 'not_ready';

	public function __construct(
		public readonly string $reason,
		public readonly int $status = 403,
	) {
		parent::__construct($reason);
	}
}

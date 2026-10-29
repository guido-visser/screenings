<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Db\Invite;
use OCA\Screenings\Db\InviteMapper;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Visitors of a public screening link. Each browser becomes an anonymous
 * invite ("Visitor 14", no email), so sessions, tracking, the heatmap, the
 * playback budget and resuming work exactly as for personal invites. A
 * signed cookie recognises the browser on a next visit.
 *
 * Clearing that cookie makes a new visitor with a fresh playback budget, so
 * new visitors per IP address are limited: a scraper can't reset its budget
 * by deleting cookies more than a few times per hour.
 */
class VisitorService {
	private const COOKIE_PREFIX = 'scr_visitor_';
	private const COOKIE_DAYS = 365;
	public const MAX_NEW_PER_IP_HOUR = 20;

	private ICache $cache;

	public function __construct(
		private InviteMapper $invites,
		private TokenGenerator $tokens,
		private IConfig $config,
		private ITimeFactory $time,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('screenings-visitors');
	}

	/**
	 * The visitor this browser already is for this screening, if any.
	 */
	public function find(Screening $screening, IRequest $request): ?Invite {
		$cookie = $request->getCookie(self::COOKIE_PREFIX . $screening->getId());
		$inviteId = is_string($cookie) ? self::verify($this->secret(), $screening->getId(), $cookie) : null;
		if ($inviteId === null) {
			return null;
		}
		try {
			$invite = $this->invites->find($inviteId);
		} catch (DoesNotExistException) {
			return null;
		}
		return $invite->isPublicVisitor() && $invite->getScreeningId() === $screening->getId() ? $invite : null;
	}

	/**
	 * The visitor for this browser, made now if it is new.
	 *
	 * @throws ValidationException too many new visitors from this address
	 */
	public function findOrCreate(Screening $screening, IRequest $request, Response $response): Invite {
		$invite = $this->find($screening, $request);
		if ($invite === null) {
			if (!$this->withinRateLimit($request->getRemoteAddress())) {
				throw new ValidationException('Too many new visits from your network. Please try again in an hour.');
			}
			$invite = new Invite();
			$invite->setScreeningId($screening->getId());
			$invite->setToken($this->tokens->linkToken());
			$invite->setName(self::visitorName(self::nextNumber($this->invites->publicVisitorNames($screening->getId()))));
			$invite->setEmail('');
			$invite->setSource(Invite::SOURCE_PUBLIC);
			$invite->setCreatedAt($this->time->getTime());
			$invite = $this->invites->insert($invite);
		}
		$expiresAt = $this->time->getTime() + self::COOKIE_DAYS * 86400;
		// Lax: public links are followed from other sites (a cross-site navigation).
		$response->addCookie(
			self::COOKIE_PREFIX . $screening->getId(),
			self::sign($this->secret(), $screening->getId(), $invite->getId()),
			(new \DateTime())->setTimestamp($expiresAt),
			'Lax',
		);
		return $invite;
	}

	public static function visitorName(int $number): string {
		return 'Visitor ' . $number;
	}

	/**
	 * One past the highest visitor number in use, so numbers aren't reused
	 * while their visitor still exists (idle visitors are deleted over time).
	 *
	 * @param list<string> $names
	 */
	public static function nextNumber(array $names): int {
		$highest = 0;
		foreach ($names as $name) {
			if (preg_match('/^Visitor (\d+)$/', $name, $m)) {
				$highest = max($highest, (int)$m[1]);
			}
		}
		return $highest + 1;
	}

	public static function sign(string $secret, int $screeningId, int $inviteId): string {
		return $inviteId . '.' . hash_hmac('sha256', 'visitor:' . $screeningId . ':' . $inviteId, $secret);
	}

	/**
	 * @return ?int the visitor's invite id, or null for a missing or forged cookie
	 */
	public static function verify(string $secret, int $screeningId, string $cookie): ?int {
		if (!preg_match('/^(\d+)\.([0-9a-f]{64})$/', $cookie, $m)) {
			return null;
		}
		return hash_equals(self::sign($secret, $screeningId, (int)$m[1]), $cookie) ? (int)$m[1] : null;
	}

	private function withinRateLimit(string $ip): bool {
		$key = 'new-' . hash('sha256', $ip) . '-' . intdiv($this->time->getTime(), 3600);
		$used = (int)($this->cache->get($key) ?? 0);
		if ($used >= self::MAX_NEW_PER_IP_HOUR) {
			return false;
		}
		$this->cache->set($key, $used + 1, 3600);
		return true;
	}

	private function secret(): string {
		return $this->config->getSystemValueString('secret');
	}
}

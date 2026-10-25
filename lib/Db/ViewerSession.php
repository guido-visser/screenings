<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One visit to the screening page by an invite that passed all checks.
 * Reloading the page starts a new session; only one per invite is active.
 *
 * @method int getInviteId()
 * @method void setInviteId(int $inviteId)
 * @method string getSecretHash()
 * @method void setSecretHash(string $secretHash)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method ?int getLastHeartbeatAt()
 * @method void setLastHeartbeatAt(?int $lastHeartbeatAt)
 * @method ?int getEndedAt()
 * @method void setEndedAt(?int $endedAt)
 * @method ?string getIp()
 * @method void setIp(?string $ip)
 * @method ?string getUserAgent()
 * @method void setUserAgent(?string $userAgent)
 * @method ?string getBrowser()
 * @method void setBrowser(?string $browser)
 * @method ?string getOs()
 * @method void setOs(?string $os)
 * @method ?string getDeviceType()
 * @method void setDeviceType(?string $deviceType)
 * @method ?int getScreenW()
 * @method void setScreenW(?int $screenW)
 * @method ?int getScreenH()
 * @method void setScreenH(?int $screenH)
 * @method ?string getCountry()
 * @method void setCountry(?string $country)
 * @method ?string getCity()
 * @method void setCity(?string $city)
 * @method ?float getLastPositionS()
 * @method void setLastPositionS(?float $lastPositionS)
 * @method ?bool getPlaying()
 * @method void setPlaying(bool $playing)
 * @method ?string getCurrentSubtitle()
 * @method void setCurrentSubtitle(?string $currentSubtitle)
 * @method ?string getCurrentQuality()
 * @method void setCurrentQuality(?string $currentQuality)
 */
class ViewerSession extends Entity implements JsonSerializable {
	protected $inviteId;
	protected $secretHash;
	protected $startedAt;
	protected $lastHeartbeatAt;
	protected $endedAt;
	protected $ip;
	protected $userAgent;
	protected $browser;
	protected $os;
	protected $deviceType;
	protected $screenW;
	protected $screenH;
	protected $country;
	protected $city;
	protected $lastPositionS;
	protected $playing;
	protected $currentSubtitle;
	protected $currentQuality;

	public function __construct() {
		$this->addType('inviteId', 'integer');
		$this->addType('startedAt', 'integer');
		$this->addType('lastHeartbeatAt', 'integer');
		$this->addType('endedAt', 'integer');
		$this->addType('screenW', 'integer');
		$this->addType('screenH', 'integer');
		$this->addType('lastPositionS', 'float');
		$this->addType('playing', 'boolean');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'inviteId' => $this->inviteId,
			'startedAt' => $this->startedAt,
			'lastHeartbeatAt' => $this->lastHeartbeatAt,
			'endedAt' => $this->endedAt,
			'ip' => $this->ip,
			'browser' => $this->browser,
			'os' => $this->os,
			'deviceType' => $this->deviceType,
			'screenW' => $this->screenW,
			'screenH' => $this->screenH,
			'country' => $this->country,
			'city' => $this->city,
			'lastPositionS' => $this->lastPositionS,
			'playing' => (bool)$this->playing,
			'currentSubtitle' => $this->currentSubtitle,
			'currentQuality' => $this->currentQuality,
		];
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method ?string getDescription()
 * @method void setDescription(?string $description)
 * @method ?int getSourceFileId()
 * @method void setSourceFileId(?int $sourceFileId)
 * @method string getDeliveryMode()
 * @method void setDeliveryMode(string $deliveryMode)
 * @method string getMediaStatus()
 * @method void setMediaStatus(string $mediaStatus)
 * @method int getMediaProgress()
 * @method void setMediaProgress(int $mediaProgress)
 * @method ?string getMediaError()
 * @method void setMediaError(?string $mediaError)
 * @method ?string getMediaToken()
 * @method void setMediaToken(?string $mediaToken)
 * @method ?string getMediaMode()
 * @method void setMediaMode(?string $mediaMode)
 * @method ?string getMediaWarning()
 * @method void setMediaWarning(?string $mediaWarning)
 * @method ?int getDurationMs()
 * @method void setDurationMs(?int $durationMs)
 * @method ?string getPasswordHash()
 * @method void setPasswordHash(?string $passwordHash)
 * @method ?int getExpiresAt()
 * @method void setExpiresAt(?int $expiresAt)
 * @method ?bool getWatermark()
 * @method void setWatermark(bool $watermark)
 * @method string getOpenLinkToken()
 * @method void setOpenLinkToken(string $openLinkToken)
 * @method ?bool getOpenLinkEnabled()
 * @method void setOpenLinkEnabled(bool $openLinkEnabled)
 * @method ?bool getTrackingNoticeEnabled()
 * @method void setTrackingNoticeEnabled(bool $trackingNoticeEnabled)
 * @method ?string getTrackingNotice()
 * @method void setTrackingNotice(?string $trackingNotice)
 * @method int getRetentionDays()
 * @method void setRetentionDays(int $retentionDays)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Screening extends Entity implements JsonSerializable {
	public const MODE_MP4 = 'mp4';
	public const MODE_HLS = 'hls';
	public const MODE_TRANSCODE = 'transcode';
	/** Plays browser-ready MP4s directly and converts everything else. */
	public const MODE_AUTO = 'auto';
	public const MODES = [self::MODE_AUTO, self::MODE_MP4, self::MODE_HLS, self::MODE_TRANSCODE];

	/** media_mode when Auto plays the file as it is; otherwise a Transcoder::MODE_* plan. */
	public const MEDIA_MODE_DIRECT = 'direct';

	public const MEDIA_NONE = 'none';
	public const MEDIA_PENDING = 'pending';
	public const MEDIA_PROCESSING = 'processing';
	public const MEDIA_READY = 'ready';
	public const MEDIA_ERROR = 'error';

	protected $ownerUid;
	protected $title;
	protected $description;
	protected $sourceFileId;
	protected $deliveryMode;
	protected $mediaStatus;
	protected $mediaProgress;
	protected $mediaError;
	protected $mediaToken;
	protected $mediaWarning;
	protected $mediaMode;
	protected $durationMs;
	protected $passwordHash;
	protected $expiresAt;
	protected $watermark;
	protected $openLinkToken;
	protected $openLinkEnabled;
	protected $trackingNoticeEnabled;
	protected $trackingNotice;
	protected $retentionDays;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('sourceFileId', 'integer');
		$this->addType('mediaProgress', 'integer');
		$this->addType('durationMs', 'integer');
		$this->addType('expiresAt', 'integer');
		$this->addType('watermark', 'boolean');
		$this->addType('openLinkEnabled', 'boolean');
		$this->addType('trackingNoticeEnabled', 'boolean');
		$this->addType('retentionDays', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/**
	 * How the current media is served: MODE_MP4 (the file itself), MODE_HLS
	 * (a packaged playlist) or MODE_TRANSCODE (ffmpeg output). Auto resolves
	 * to MODE_MP4 or MODE_TRANSCODE, depending on what it chose.
	 */
	public function servedAs(): string {
		if ($this->deliveryMode === self::MODE_AUTO) {
			return $this->mediaMode === self::MEDIA_MODE_DIRECT ? self::MODE_MP4 : self::MODE_TRANSCODE;
		}
		return $this->deliveryMode;
	}

	public function isExpired(int $now): bool {
		return $this->expiresAt !== null && $this->expiresAt <= $now;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'title' => $this->title,
			'description' => $this->description,
			'sourceFileId' => $this->sourceFileId,
			'deliveryMode' => $this->deliveryMode,
			'mediaStatus' => $this->mediaStatus,
			'mediaProgress' => $this->mediaProgress,
			'mediaError' => $this->mediaError,
			'mediaWarning' => $this->mediaWarning,
			'mediaMode' => $this->mediaMode,
			'durationMs' => $this->durationMs,
			'hasPassword' => $this->passwordHash !== null,
			'expiresAt' => $this->expiresAt,
			'watermark' => (bool)$this->watermark,
			'openLinkToken' => $this->openLinkToken,
			'openLinkEnabled' => (bool)$this->openLinkEnabled,
			'trackingNoticeEnabled' => $this->trackingNoticeEnabled !== false,
			'trackingNotice' => $this->trackingNotice,
			'retentionDays' => $this->retentionDays,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}

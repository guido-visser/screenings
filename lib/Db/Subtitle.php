<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getScreeningId()
 * @method void setScreeningId(int $screeningId)
 * @method string getLang()
 * @method void setLang(string $lang)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method ?bool getIsDefault()
 * @method void setIsDefault(bool $isDefault)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Subtitle extends Entity implements JsonSerializable {
	protected $screeningId;
	protected $lang;
	protected $label;
	protected $isDefault;
	protected $fileName;
	protected $createdAt;

	public function __construct() {
		$this->addType('screeningId', 'integer');
		$this->addType('isDefault', 'boolean');
		$this->addType('createdAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'screeningId' => $this->screeningId,
			'lang' => $this->lang,
			'label' => $this->label,
			'isDefault' => (bool)$this->isDefault,
			'createdAt' => $this->createdAt,
		];
	}
}

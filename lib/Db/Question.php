<?php

declare(strict_types=1);

namespace OCA\Screenings\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A closing question of a screening.
 *
 * @method int getScreeningId()
 * @method void setScreeningId(int $screeningId)
 * @method int getSort()
 * @method void setSort(int $sort)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method ?string getOptions()
 * @method void setOptions(?string $options)
 * @method ?bool getRequired()
 * @method void setRequired(bool $required)
 */
class Question extends Entity implements JsonSerializable {
	/** 1 to 5 stars. */
	public const KIND_RATING = 'rating';
	public const KIND_TEXT = 'text';
	/** One of the owner's options. */
	public const KIND_CHOICE = 'choice';
	public const KINDS = [self::KIND_RATING, self::KIND_TEXT, self::KIND_CHOICE];

	protected $screeningId;
	protected $sort;
	protected $kind;
	protected $label;
	protected $options;
	protected $required;

	public function __construct() {
		$this->addType('screeningId', 'integer');
		$this->addType('sort', 'integer');
		$this->addType('required', 'boolean');
	}

	/**
	 * @return list<string>
	 */
	public function optionList(): array {
		$decoded = $this->options === null ? null : json_decode($this->options, true);
		return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'kind' => $this->kind,
			'label' => $this->label,
			'options' => $this->optionList(),
			'required' => (bool)$this->required,
		];
	}
}

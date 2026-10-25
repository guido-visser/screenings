<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\AppInfo\Application;
use OCA\Screenings\Db\Subtitle;
use OCA\Screenings\Db\SubtitleMapper;
use OCA\Screenings\Exception\NotFoundException;
use OCA\Screenings\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FileNotFoundException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Security\ISecureRandom;

/**
 * Subtitle tracks are stored as .vtt files in the app's own data folder,
 * outside every user's Files, and served to viewers by the app only.
 */
class SubtitleService {
	public const MAX_BYTES = 2 * 1024 * 1024;
	private const FOLDER = 'subtitles';

	private IAppData $appData;

	public function __construct(
		private SubtitleMapper $mapper,
		private SubtitleConverter $converter,
		private ITimeFactory $time,
		private ISecureRandom $random,
		IAppDataFactory $appDataFactory,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/**
	 * @return Subtitle[]
	 */
	public function listForScreening(int $screeningId): array {
		return $this->mapper->findForScreening($screeningId);
	}

	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Subtitle {
		try {
			return $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Subtitle not found');
		}
	}

	/**
	 * @throws ValidationException
	 */
	public function add(int $screeningId, string $fileName, string $content, string $lang, string $label): Subtitle {
		if (strlen($content) > self::MAX_BYTES) {
			throw new ValidationException('Subtitle files can be at most 2 MB', 'file');
		}
		[$lang, $label] = $this->validateMeta($lang, $label, $fileName);
		$vtt = $this->converter->toVtt($content, $fileName);

		$storedName = $screeningId . '-' . $this->random->generate(16, ISecureRandom::CHAR_ALPHANUMERIC) . '.vtt';
		$this->folder()->newFile($storedName, $vtt);

		$subtitle = new Subtitle();
		$subtitle->setScreeningId($screeningId);
		$subtitle->setLang($lang);
		$subtitle->setLabel($label);
		$subtitle->setIsDefault(false);
		$subtitle->setFileName($storedName);
		$subtitle->setCreatedAt($this->time->getTime());
		return $this->mapper->insert($subtitle);
	}

	/**
	 * @throws ValidationException
	 */
	public function update(Subtitle $subtitle, array $data): Subtitle {
		if (array_key_exists('lang', $data) || array_key_exists('label', $data)) {
			[$lang, $label] = $this->validateMeta(
				(string)($data['lang'] ?? $subtitle->getLang()),
				(string)($data['label'] ?? $subtitle->getLabel()),
				'',
			);
			$subtitle->setLang($lang);
			$subtitle->setLabel($label);
		}
		if (array_key_exists('isDefault', $data)) {
			if ($data['isDefault']) {
				$this->mapper->clearDefault($subtitle->getScreeningId());
			}
			$subtitle->setIsDefault((bool)$data['isDefault']);
		}
		return $this->mapper->update($subtitle);
	}

	public function delete(Subtitle $subtitle): void {
		try {
			$this->folder()->getFile($subtitle->getFileName())->delete();
		} catch (FileNotFoundException) {
			// Already gone; still remove the row.
		}
		$this->mapper->delete($subtitle);
	}

	public function deleteAllForScreening(int $screeningId): void {
		foreach ($this->mapper->findForScreening($screeningId) as $subtitle) {
			$this->delete($subtitle);
		}
	}

	/**
	 * @throws NotFoundException
	 */
	public function content(Subtitle $subtitle): string {
		try {
			return $this->folder()->getFile($subtitle->getFileName())->getContent();
		} catch (FileNotFoundException) {
			throw new NotFoundException('Subtitle file missing');
		}
	}

	/**
	 * @return array{string, string}
	 * @throws ValidationException
	 */
	private function validateMeta(string $lang, string $label, string $fileName): array {
		$lang = strtolower(trim($lang));
		if (!preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $lang)) {
			throw new ValidationException('Use a language code like "en", "nl" or "pt-br"', 'lang');
		}
		$label = trim($label);
		if ($label === '') {
			$label = $fileName !== '' ? pathinfo($fileName, PATHINFO_FILENAME) : strtoupper($lang);
		}
		if (mb_strlen($label) > 64) {
			throw new ValidationException('The label is too long', 'label');
		}
		return [$lang, $label];
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (FileNotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Db\Screening;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Media\MediaService;
use OCP\Files\File;
use OCP\Files\NotPermittedException;

/**
 * Adds the subtitle files that sit next to a screening's video and share
 * its name ("film.mp4" with "film.en.srt", "film.nl.srt"), like media
 * players pick them up. See SubtitleFileName for the naming rules.
 */
class SubtitleImporter {
	public function __construct(
		private MediaService $media,
		private SubtitleService $subtitles,
	) {
	}

	/**
	 * Languages the screening already has (same language and label) are
	 * skipped, so running this again only adds new files.
	 *
	 * @return array{added: list<array{file: string, lang: string, label: string}>, skipped: list<array{file: string, reason: string}>}
	 */
	public function importSiblings(Screening $screening): array {
		$result = ['added' => [], 'skipped' => []];
		$video = $this->media->sourceFile($screening);
		if ($video === null) {
			return $result;
		}

		$existing = [];
		foreach ($this->subtitles->listForScreening($screening->getId()) as $subtitle) {
			$existing[self::key($subtitle->getLang(), $subtitle->getLabel())] = true;
		}

		$candidates = [];
		foreach ($video->getParent()->getDirectoryListing() as $node) {
			if (!$node instanceof File) {
				continue;
			}
			$match = SubtitleFileName::match($video->getName(), $node->getName());
			if ($match !== null) {
				$candidates[] = [$node, $match];
			}
		}
		// Stable, readable order in the player's menu.
		usort($candidates, fn (array $a, array $b) => strcmp($a[1]['label'], $b[1]['label']));

		foreach ($candidates as [$file, $match]) {
			$name = $file->getName();
			if (isset($existing[self::key($match['lang'], $match['label'])])) {
				continue;
			}
			if ($file->getSize() > SubtitleService::MAX_BYTES) {
				$result['skipped'][] = ['file' => $name, 'reason' => 'larger than 2 MB'];
				continue;
			}
			try {
				$subtitle = $this->subtitles->add($screening->getId(), $name, $file->getContent(), $match['lang'], $match['label']);
				if ($match['default']) {
					$this->subtitles->update($subtitle, ['isDefault' => true]);
				}
			} catch (ValidationException $e) {
				$result['skipped'][] = ['file' => $name, 'reason' => $e->getMessage()];
				continue;
			} catch (NotPermittedException) {
				$result['skipped'][] = ['file' => $name, 'reason' => 'not readable'];
				continue;
			}
			$existing[self::key($match['lang'], $match['label'])] = true;
			$result['added'][] = ['file' => $name, 'lang' => $match['lang'], 'label' => $match['label']];
		}
		return $result;
	}

	private static function key(string $lang, string $label): string {
		return mb_strtolower($lang . '|' . $label);
	}
}

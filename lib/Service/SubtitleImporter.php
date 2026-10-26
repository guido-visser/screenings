<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

use OCA\Screenings\Db\Screening;
use OCA\Screenings\Db\Subtitle;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Media\MediaService;
use OCP\Files\File;
use OCP\Files\NotPermittedException;

/**
 * Adds the subtitle files that sit next to a screening's video and share
 * its name ("film.mp4" with "film.en.srt", "film.nl.srt"), like media
 * players pick them up, and keeps them up to date when the files change.
 * See SubtitleFileName for the naming rules.
 *
 * Each imported subtitle remembers its file (source_file_id), and only
 * that file updates it; subtitles uploaded by hand are never overwritten.
 * Deleting a file never removes its subtitle; the owner does that in
 * Settings, so viewers don't lose subtitles to an accidental delete.
 */
class SubtitleImporter {
	public const ADDED = 'added';
	public const UPDATED = 'updated';
	public const UNCHANGED = 'unchanged';

	public function __construct(
		private MediaService $media,
		private SubtitleService $subtitles,
	) {
	}

	/**
	 * All matching files in the video's folder.
	 *
	 * @return array{added: list<array{file: string, lang: string, label: string}>, updated: list<array{file: string, lang: string, label: string}>, skipped: list<array{file: string, reason: string}>}
	 */
	public function importSiblings(Screening $screening): array {
		$result = ['added' => [], 'updated' => [], 'skipped' => []];
		$video = $this->media->sourceFile($screening);
		if ($video === null) {
			return $result;
		}

		$candidates = [];
		foreach ($video->getParent()->getDirectoryListing() as $node) {
			if ($node instanceof File && ($match = SubtitleFileName::match($video->getName(), $node->getName())) !== null) {
				$candidates[] = [$node, $match];
			}
		}
		// Stable, readable order in the player's menu.
		usort($candidates, fn (array $a, array $b) => strcmp($a[1]['label'], $b[1]['label']));

		foreach ($candidates as [$file, $match]) {
			try {
				$outcome = $this->importMatched($screening, $file, $match);
			} catch (ValidationException $e) {
				$result['skipped'][] = ['file' => $file->getName(), 'reason' => $e->getMessage()];
				continue;
			}
			if ($outcome === self::ADDED || $outcome === self::UPDATED) {
				$result[$outcome][] = ['file' => $file->getName(), 'lang' => $match['lang'], 'label' => $match['label']];
			}
		}
		return $result;
	}

	/**
	 * One file that was added or changed next to the screening's video.
	 *
	 * @return ?string ADDED, UPDATED or UNCHANGED; null if the file isn't a subtitle of this video
	 * @throws ValidationException when the file can't be used
	 */
	public function importFile(Screening $screening, File $video, File $file): ?string {
		$match = SubtitleFileName::match($video->getName(), $file->getName());
		return $match === null ? null : $this->importMatched($screening, $file, $match);
	}

	/**
	 * @param array{lang: string, label: string, default: bool} $match
	 * @throws ValidationException
	 */
	private function importMatched(Screening $screening, File $file, array $match): string {
		$existing = $this->subtitles->listForScreening($screening->getId());
		$fileId = $file->getId();
		$linked = self::find($existing, fn (Subtitle $s) => $s->getSourceFileId() === $fileId);
		$sameLanguage = self::find($existing, fn (Subtitle $s) => mb_strtolower($s->getLang() . '|' . $s->getLabel()) === mb_strtolower($match['lang'] . '|' . $match['label']));

		if ($linked === null && $sameLanguage !== null) {
			// This language is already there: from another file, or uploaded
			// by hand (which a file next to the video never overwrites).
			return self::UNCHANGED;
		}
		if ($file->getSize() > SubtitleService::MAX_BYTES) {
			throw new ValidationException('larger than 2 MB');
		}
		try {
			$content = $file->getContent();
		} catch (NotPermittedException) {
			throw new ValidationException('not readable');
		}

		if ($linked !== null) {
			$changed = $this->subtitles->replace($linked, $file->getName(), $content, $match['lang'], $match['label'], $fileId);
			return $changed ? self::UPDATED : self::UNCHANGED;
		}

		$subtitle = $this->subtitles->add($screening->getId(), $file->getName(), $content, $match['lang'], $match['label'], $fileId);
		if ($match['default']) {
			$this->subtitles->update($subtitle, ['isDefault' => true]);
		}
		return self::ADDED;
	}

	/**
	 * @param Subtitle[] $subtitles
	 */
	private static function find(array $subtitles, callable $test): ?Subtitle {
		foreach ($subtitles as $subtitle) {
			if ($test($subtitle)) {
				return $subtitle;
			}
		}
		return null;
	}
}

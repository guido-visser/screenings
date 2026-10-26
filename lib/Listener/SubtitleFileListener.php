<?php

declare(strict_types=1);

namespace OCA\Screenings\Listener;

use OCA\Screenings\Db\ScreeningMapper;
use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Service\SubtitleFileName;
use OCA\Screenings\Service\SubtitleImporter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodeEvent;
use OCP\Files\Events\Node\AbstractNodesEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * A subtitle file was uploaded, changed, copied or renamed next to a video
 * that has screenings ("film.en.srt" next to "film.mp4"): add it to those
 * screenings, or update the subtitle it already provides.
 *
 * Deletions are left alone on purpose: the owner removes subtitles in
 * Settings, so an accidental delete (or a sync client briefly removing a
 * file) never takes subtitles away from viewers.
 *
 * @template-implements IEventListener<NodeCreatedEvent|NodeWrittenEvent|NodeRenamedEvent|NodeCopiedEvent>
 */
class SubtitleFileListener implements IEventListener {
	public function __construct(
		private ScreeningMapper $screenings,
		private SubtitleImporter $importer,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		$node = match (true) {
			$event instanceof AbstractNodeEvent => $event->getNode(),
			// Renamed and copied: the file under its new name.
			$event instanceof AbstractNodesEvent => $event->getTarget(),
			default => null,
		};
		if (!$node instanceof File || !self::isUserSubtitle($node)) {
			return;
		}

		try {
			$this->importNextToVideos($node);
		} catch (\Throwable $e) {
			// Never let a subtitle problem break an upload.
			$this->logger->warning('Updating screenings for subtitle file ' . $node->getPath() . ' failed', ['exception' => $e]);
		}
	}

	private function importNextToVideos(File $subtitle): void {
		$videos = [];
		foreach ($subtitle->getParent()->getDirectoryListing() as $node) {
			if ($node instanceof File && $node->getId() !== $subtitle->getId()
				&& SubtitleFileName::match($node->getName(), $subtitle->getName()) !== null) {
				$videos[$node->getId()] = $node;
			}
		}
		foreach ($this->screenings->findBySourceFileIds(array_keys($videos)) as $screening) {
			try {
				$this->importer->importFile($screening, $videos[$screening->getSourceFileId()], $subtitle);
			} catch (ValidationException $e) {
				// E.g. an upload that has no content yet; the write that follows brings it.
				$this->logger->debug('Subtitle file ' . $subtitle->getName() . ' not used for screening ' . $screening->getId() . ': ' . $e->getMessage());
			}
		}
	}

	/**
	 * .srt/.vtt in someone's Files ("/<user>/files/…"), not in app data,
	 * where this app stores its own .vtt copies.
	 */
	private static function isUserSubtitle(Node $node): bool {
		$extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
		if (!in_array($extension, SubtitleFileName::EXTENSIONS, true)) {
			return false;
		}
		$parts = explode('/', $node->getPath(), 4);
		return ($parts[2] ?? '') === 'files';
	}
}

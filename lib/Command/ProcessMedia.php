<?php

declare(strict_types=1);

namespace OCA\Screenings\Command;

use OCA\Screenings\Db\Screening;
use OCA\Screenings\Db\ScreeningMapper;
use OCA\Screenings\Media\MediaProcessor;
use OCA\Screenings\Media\MediaService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessMedia extends Command {
	public function __construct(
		private ScreeningMapper $mapper,
		private MediaService $media,
		private MediaProcessor $processor,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('screenings:process-media')
			->setDescription('Package or transcode a screening\'s media now, instead of waiting for the background job')
			->addArgument('screening-id', InputArgument::REQUIRED, 'Screening ID')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Process again even if the media is ready or failed');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$screening = $this->mapper->findById((int)$input->getArgument('screening-id'));
		} catch (DoesNotExistException) {
			$output->writeln('<error>Screening not found</error>');
			return 1;
		}

		if ($screening->getDeliveryMode() === Screening::MODE_MP4) {
			$output->writeln('Direct MP4 needs no processing.');
			return 0;
		}

		$waiting = in_array($screening->getMediaStatus(), [Screening::MEDIA_PENDING, Screening::MEDIA_PROCESSING], true);
		if (!$waiting && !$input->getOption('force')) {
			$output->writeln(sprintf('Media is "%s". Use --force to process it again.', $screening->getMediaStatus()));
			return 1;
		}
		// A fresh run token: a queued or running background job for this
		// screening becomes stale and discards its results.
		$this->media->prepare($screening);
		$this->mapper->update($screening);

		$bar = new ProgressBar($output, 100);
		$bar->start();
		$outcome = $this->processor->process($screening->getId(), $screening->getMediaToken(), function (float $fraction) use ($bar): void {
			$bar->setProgress((int)floor($fraction * 100));
		});
		$bar->finish();
		$output->writeln('');

		$screening = $this->mapper->findById($screening->getId());
		$output->writeln('Result: ' . $outcome . ($screening->getMediaError() ? ' (' . $screening->getMediaError() . ')' : ''));
		return $outcome === 'ready' ? 0 : 1;
	}
}

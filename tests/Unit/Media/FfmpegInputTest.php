<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Media;

use OCA\Screenings\Media\Ffmpeg;
use OCA\Screenings\Media\MediaException;
use OCA\Screenings\Media\ProcessRunner;
use OCP\IAppConfig;
use OCP\IBinaryFinder;
use PHPUnit\Framework\TestCase;

/**
 * ffmpeg must only open plain media containers: a ".mkv" or ".mp4" that is
 * really a playlist or file list would otherwise read other files by path.
 * The probe tests need ffmpeg and ffprobe (the Nextcloud dev container has
 * them) and are skipped elsewhere.
 */
class FfmpegInputTest extends TestCase {
	private string $dir = '';

	protected function tearDown(): void {
		if ($this->dir !== '') {
			array_map('unlink', glob($this->dir . '/*') ?: []);
			rmdir($this->dir);
		}
	}

	public function testInputOptionsAllowOnlyPlainContainers(): void {
		$options = Ffmpeg::inputOptions();

		$this->assertSame(['-protocol_whitelist', 'file', '-format_whitelist'], array_slice($options, 0, 3));
		$formats = explode(',', $options[3]);
		foreach (['mov', 'matroska', 'mxf'] as $needed) {
			$this->assertContains($needed, $formats);
		}
		foreach (['hls', 'concat', 'image2', 'tty', 'ffmetadata'] as $referencing) {
			$this->assertNotContains($referencing, $formats);
		}
	}

	public function testProbeReadsARealVideo(): void {
		$ffmpeg = $this->ffmpeg();
		$video = $this->dir . '/clip.mp4';
		$this->makeVideo($video);

		$info = $ffmpeg->probe($video);

		$this->assertSame('h264', $info['videoCodec']);
		$this->assertSame(1000, $info['durationMs']);
	}

	public function testProbeRefusesAFileListDisguisedAsVideo(): void {
		$ffmpeg = $this->ffmpeg();
		$this->makeVideo($this->dir . '/other.mp4');
		// Without the whitelist ffprobe opens this as "concat" and reads other.mp4.
		file_put_contents($this->dir . '/film.mkv', "ffconcat version 1.0\nfile other.mp4\n");

		$this->expectException(MediaException::class);
		$this->expectExceptionMessage('not a video container');
		$ffmpeg->probe($this->dir . '/film.mkv');
	}

	private function ffmpeg(): Ffmpeg {
		foreach (['ffmpeg', 'ffprobe'] as $binary) {
			if (!is_executable('/usr/bin/' . $binary)) {
				$this->markTestSkipped($binary . ' is not installed here');
			}
		}
		$this->dir = sys_get_temp_dir() . '/scr-ffmpeg-' . bin2hex(random_bytes(4));
		mkdir($this->dir);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$finder = $this->createMock(IBinaryFinder::class);
		$finder->method('findBinaryPath')->willReturnCallback(fn (string $name) => '/usr/bin/' . $name);
		return new Ffmpeg($config, $finder);
	}

	private function makeVideo(string $path): void {
		$result = ProcessRunner::run(['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=duration=1:size=160x120:rate=25',
			'-c:v', 'libx264', '-pix_fmt', 'yuv420p', $path], 60);
		$this->assertSame(0, $result['exitCode'], $result['stderr']);
	}
}

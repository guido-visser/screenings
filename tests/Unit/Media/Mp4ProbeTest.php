<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Media;

use OCA\Screenings\Media\MediaException;
use OCA\Screenings\Media\Mp4Probe;
use PHPUnit\Framework\TestCase;

class Mp4ProbeTest extends TestCase {
	private static function box(string $type, string $body): string {
		return pack('N', 8 + strlen($body)) . $type . $body;
	}

	private static function mvhd(int $timescale, int $duration): string {
		// version 0, flags, creation, modification, timescale, duration, then padding
		return self::box('mvhd', "\0\0\0\0" . pack('NNNN', 0, 0, $timescale, $duration) . str_repeat("\0", 80));
	}

	private static function trak(string $handler, string $codec): string {
		$hdlr = self::box('hdlr', "\0\0\0\0" . "\0\0\0\0" . $handler . str_repeat("\0", 12) . "\0");
		$stsd = self::box('stsd', "\0\0\0\0" . pack('N', 1) . pack('N', 16) . $codec . str_repeat("\0", 8));
		$stbl = self::box('stbl', $stsd);
		$minf = self::box('minf', $stbl);
		$mdia = self::box('mdia', $hdlr . $minf);
		return self::box('trak', $mdia);
	}

	private static function file(string ...$boxes): array {
		$data = implode('', $boxes);
		$stream = fopen('php://memory', 'w+b');
		fwrite($stream, $data);
		rewind($stream);
		return [$stream, strlen($data)];
	}

	private static function moov(string $videoCodec = 'avc1'): string {
		return self::box('moov', self::mvhd(1000, 125500) . self::trak('vide', $videoCodec) . self::trak('soun', 'mp4a'));
	}

	public function testReadsFaststartFile(): void {
		[$stream, $size] = self::file(self::box('ftyp', 'isom'), self::moov(), self::box('mdat', str_repeat('x', 100)));

		$info = (new Mp4Probe())->probe($stream, $size);

		$this->assertTrue($info->faststart);
		$this->assertSame(125500, $info->durationMs);
		$this->assertSame('avc1', $info->videoCodec);
		$this->assertSame('mp4a', $info->audioCodec);
		$this->assertSame([], Mp4Probe::warnings($info));
	}

	public function testDetectsIndexAtEnd(): void {
		[$stream, $size] = self::file(self::box('ftyp', 'isom'), self::box('mdat', str_repeat('x', 100)), self::moov());

		$info = (new Mp4Probe())->probe($stream, $size);

		$this->assertFalse($info->faststart);
		$this->assertStringContainsString('fast start', Mp4Probe::warnings($info)[0]);
	}

	public function testWarnsAboutHevc(): void {
		[$stream, $size] = self::file(self::box('ftyp', 'isom'), self::moov('hvc1'));

		$warnings = Mp4Probe::warnings((new Mp4Probe())->probe($stream, $size));

		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('HEVC', $warnings[0]);
	}

	public function testHandlesLargeSizeHeader(): void {
		// mdat with a 64-bit size (size field = 1)
		$mdatBody = str_repeat('x', 50);
		$mdat = pack('N', 1) . 'mdat' . pack('J', 16 + strlen($mdatBody)) . $mdatBody;
		[$stream, $size] = self::file(self::box('ftyp', 'isom'), $mdat, self::moov());

		$this->assertSame(125500, (new Mp4Probe())->probe($stream, $size)->durationMs);
	}

	public function testRejectsNonMp4(): void {
		[$stream, $size] = self::file('not an mp4 file at all');
		$this->expectException(MediaException::class);
		(new Mp4Probe())->probe($stream, $size);
	}

	public function testRejectsMissingIndex(): void {
		[$stream, $size] = self::file(self::box('ftyp', 'isom'), self::box('mdat', 'xxxx'));
		$this->expectException(MediaException::class);
		$this->expectExceptionMessage('no index');
		(new Mp4Probe())->probe($stream, $size);
	}
}

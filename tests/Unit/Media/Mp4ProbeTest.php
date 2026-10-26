<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Media;

use OCA\Screenings\Media\MediaException;
use OCA\Screenings\Media\Mp4Info;
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

	private static function trak(string $handler, string $codec, ?int $avcProfile = null): string {
		$hdlr = self::box('hdlr', "\0\0\0\0" . "\0\0\0\0" . $handler . str_repeat("\0", 12) . "\0");
		if ($avcProfile !== null) {
			// VisualSampleEntry: 78 bytes of fields, then an avcC child box
			// (configurationVersion 1, then the profile).
			$entry = self::box($codec, str_repeat("\0", 78) . self::box('avcC', "\x01" . chr($avcProfile) . "\0\x28\xff"));
		} else {
			$entry = pack('N', 16) . $codec . str_repeat("\0", 8);
		}
		$stsd = self::box('stsd', "\0\0\0\0" . pack('N', 1) . $entry);
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

	private static function moov(string $videoCodec = 'avc1', ?int $avcProfile = null, string $audioCodec = 'mp4a'): string {
		return self::box('moov', self::mvhd(1000, 125500) . self::trak('vide', $videoCodec, $avcProfile) . self::trak('soun', $audioCodec));
	}

	private static function probe(string $moov): Mp4Info {
		[$stream, $size] = self::file(self::box('ftyp', 'isom'), $moov);
		return (new Mp4Probe())->probe($stream, $size);
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
		// Plays fine since the stream allows index reads; nothing to warn about.
		$this->assertSame([], Mp4Probe::warnings($info));
		// The index sits right after ftyp (12 bytes) and mdat (108 bytes).
		$this->assertSame(120, $info->moovOffset);
		$this->assertSame($size - 120, $info->moovSize);
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

	public function testReadsH264Profile(): void {
		$this->assertSame(100, self::probe(self::moov('avc1', 100))->videoProfile);
		$this->assertNull(self::probe(self::moov('avc1'))->videoProfile, 'no avcC box');
	}

	public function testBrowserSafe(): void {
		$this->assertTrue(Mp4Probe::browserSafe(self::probe(self::moov('avc1', 100))), 'H.264 High + AAC');
		$this->assertTrue(Mp4Probe::browserSafe(self::probe(self::moov('avc1'))), 'H.264, profile unknown');
		$this->assertFalse(Mp4Probe::browserSafe(self::probe(self::moov('avc1', 110))), 'H.264 High 10');
		$this->assertFalse(Mp4Probe::browserSafe(self::probe(self::moov('avc1', 122))), 'H.264 High 4:2:2');
		$this->assertFalse(Mp4Probe::browserSafe(self::probe(self::moov('hvc1'))), 'HEVC');
		$this->assertFalse(Mp4Probe::browserSafe(self::probe(self::moov('apcn'))), 'ProRes');
		$this->assertFalse(Mp4Probe::browserSafe(self::probe(self::moov('avc1', 100, 'lpcm'))), 'PCM audio');
		$this->assertFalse(Mp4Probe::browserSafe(self::probe(self::moov('avc1', 100, 'ac-3'))), 'Dolby audio');
	}

	public function testWarnsAboutTenBitH264(): void {
		$warnings = Mp4Probe::warnings(self::probe(self::moov('avc1', 110)));
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('10-bit', $warnings[0]);
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Service;

use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Service\SubtitleConverter;
use PHPUnit\Framework\TestCase;

class SubtitleConverterTest extends TestCase {
	private SubtitleConverter $converter;

	protected function setUp(): void {
		$this->converter = new SubtitleConverter();
	}

	public function testConvertsSrtTimestampsAndAddsHeader(): void {
		$srt = "1\r\n00:00:01,000 --> 00:00:02,500\r\nHello\r\n\r\n2\r\n00:00:03,000 --> 00:00:04,000\r\nWorld\r\n";

		$vtt = $this->converter->toVtt($srt, 'film.srt');

		$this->assertSame(
			"WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.500\nHello\n\n2\n00:00:03.000 --> 00:00:04.000\nWorld\n",
			$vtt,
		);
	}

	public function testStripsByteOrderMark(): void {
		$srt = "\xEF\xBB\xBF1\n00:00:01,000 --> 00:00:02,000\nHi\n";

		$this->assertStringStartsWith("WEBVTT\n\n1\n", $this->converter->toVtt($srt, 'a.srt'));
	}

	public function testConvertsWindows1252ToUtf8(): void {
		$srt = "1\n00:00:01,000 --> 00:00:02,000\nCaf\xE9\n";

		$this->assertStringContainsString("Café", $this->converter->toVtt($srt, 'a.srt'));
	}

	public function testKeepsCueSettingsAfterTimestamps(): void {
		$srt = "1\n00:00:01,000 --> 00:00:02,000 X1:40 X2:600\nHi\n";

		$this->assertStringContainsString('00:00:01.000 --> 00:00:02.000 X1:40', $this->converter->toVtt($srt, 'a.srt'));
	}

	public function testPassesThroughValidVtt(): void {
		$vtt = "WEBVTT\r\n\r\n00:01.000 --> 00:02.000\r\nHi\r\n";

		$this->assertSame("WEBVTT\n\n00:01.000 --> 00:02.000\nHi\n", $this->converter->toVtt($vtt, 'a.vtt'));
	}

	public function testRejectsVttWithoutHeader(): void {
		$this->expectException(ValidationException::class);
		$this->converter->toVtt("00:01.000 --> 00:02.000\nHi\n", 'a.vtt');
	}

	public function testRejectsFileWithoutCues(): void {
		$this->expectException(ValidationException::class);
		$this->converter->toVtt("just some text\n", 'a.srt');
	}

	public function testRejectsOtherExtensions(): void {
		$this->expectException(ValidationException::class);
		$this->converter->toVtt("1\n00:00:01,000 --> 00:00:02,000\nHi\n", 'a.ass');
	}
}

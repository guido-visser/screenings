<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Service;

use OCA\Screenings\Service\SubtitleFileName;
use PHPUnit\Framework\TestCase;

class SubtitleFileNameTest extends TestCase {
	private static function lang(string $subtitle, string $video = 'Bloedtranen (2023).mp4'): ?string {
		return SubtitleFileName::match($video, $subtitle)['lang'] ?? null;
	}

	public function testLanguageCodesAndNames(): void {
		$this->assertSame('en', self::lang('Bloedtranen (2023).en.srt'));
		$this->assertSame('nl', self::lang('Bloedtranen (2023).NL.srt'), 'case');
		$this->assertSame('nl', self::lang('bloedtranen (2023).nl.srt'), 'video name case');
		$this->assertSame('nl', self::lang('Bloedtranen (2023).nld.srt'), 'ISO 639-2');
		$this->assertSame('de', self::lang('Bloedtranen (2023).ger.vtt'), 'ISO 639-2 bibliographic');
		$this->assertSame('pt-br', self::lang('Bloedtranen (2023).pt-BR.srt'), 'region');
		$this->assertSame('pt-br', self::lang('Bloedtranen (2023)_pt_BR.srt'), 'underscores');
		$this->assertSame('nl', self::lang('Bloedtranen (2023).Dutch.srt'), 'English name');
		$this->assertSame('nl', self::lang('Bloedtranen (2023).Nederlands.srt'), 'native name');
		$this->assertSame('fr', self::lang('Bloedtranen (2023) - fr.srt'), 'dash separator');
		$this->assertSame('und', self::lang('Bloedtranen (2023).srt'), 'no language');
		$this->assertSame('hi', self::lang('Bloedtranen (2023).hi.srt'), '"hi" alone is Hindi');
	}

	public function testFlags(): void {
		$sdh = SubtitleFileName::match('film.mp4', 'film.en.sdh.srt');
		$this->assertTrue($sdh['sdh']);
		$this->assertFalse($sdh['forced']);
		$this->assertTrue(SubtitleFileName::match('film.mp4', 'film.en.hi.srt')['sdh'], '"hi" after a language is SDH');
		$this->assertTrue(SubtitleFileName::match('film.mp4', 'film.nl.forced.srt')['forced']);
		$this->assertTrue(SubtitleFileName::match('film.mp4', 'film.en.default.srt')['default']);
		$this->assertSame('en', SubtitleFileName::match('film.mp4', 'film.en.CC.srt')['lang']);
	}

	public function testOtherFilesDoNotMatch(): void {
		$this->assertNull(SubtitleFileName::match('film.mp4', 'film-trailer.en.srt'), 'another video');
		$this->assertNull(SubtitleFileName::match('film.mp4', 'film2.srt'), 'no separator');
		$this->assertNull(SubtitleFileName::match('film.mp4', 'other.en.srt'));
		$this->assertNull(SubtitleFileName::match('film.mp4', 'film.en.ass'), 'unsupported format');
		$this->assertNull(SubtitleFileName::match('film.mp4', 'film.en.nl.srt'), 'two languages');
		$this->assertNull(SubtitleFileName::match('film.mp4', 'film.en.v2.srt'), 'unknown word');
		$this->assertNull(SubtitleFileName::match('film.mp4', 'film.xx.srt'), 'unknown code');
	}

	public function testVideoNamesWithDots(): void {
		$this->assertSame('en', self::lang('Film.2023.1080p.en.srt', 'Film.2023.1080p.mkv'));
	}

	public function testLabels(): void {
		if (!extension_loaded('intl')) {
			$this->markTestSkipped('Language names come from intl');
		}
		$this->assertSame('Nederlands', SubtitleFileName::match('film.mp4', 'film.nl.srt')['label']);
		$this->assertSame('English (SDH)', SubtitleFileName::match('film.mp4', 'film.en.sdh.srt')['label']);
		$this->assertSame('Nederlands (forced)', SubtitleFileName::match('film.mp4', 'film.nl.forced.srt')['label']);
		$this->assertSame('Subtitles', SubtitleFileName::match('film.mp4', 'film.srt')['label']);
	}
}

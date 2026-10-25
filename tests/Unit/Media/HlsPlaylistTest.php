<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Media;

use OCA\Screenings\Media\HlsPackager;
use OCA\Screenings\Media\HlsPlaylist;
use OCA\Screenings\Media\MediaException;
use OCA\Screenings\Media\Transcoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HlsPlaylistTest extends TestCase {
	private const MEDIA = "#EXTM3U\r\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:4\n#EXT-X-MEDIA-SEQUENCE:0\n#EXTINF:4.000000,\nseg_000.ts\n#EXTINF:3.5,\nsub/seg_001.ts\n#EXT-X-ENDLIST\n";

	public function testParsesMediaPlaylist(): void {
		$parsed = HlsPlaylist::parseMedia(self::MEDIA);

		$this->assertSame(0, $parsed['mediaSequence']);
		$this->assertSame(4, $parsed['targetDuration']);
		$this->assertSame([
			['duration' => 4.0, 'uri' => 'seg_000.ts'],
			['duration' => 3.5, 'uri' => 'sub/seg_001.ts'],
		], $parsed['segments']);
		$this->assertSame(7500, HlsPlaylist::totalDurationMs($parsed['segments']));
	}

	public function testMediaPlaylistIsNotAMaster(): void {
		$this->assertNull(HlsPlaylist::parseMaster(self::MEDIA));
	}

	public function testParsesMasterPlaylist(): void {
		$master = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080\nv0/index.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=1400000,RESOLUTION=854x480\nv2/index.m3u8\n";

		$this->assertSame([
			['uri' => 'v0/index.m3u8', 'attributes' => 'BANDWIDTH=5000000,RESOLUTION=1920x1080'],
			['uri' => 'v2/index.m3u8', 'attributes' => 'BANDWIDTH=1400000,RESOLUTION=854x480'],
		], HlsPlaylist::parseMaster($master));
	}

	#[DataProvider('rejectedPlaylists')]
	public function testRejectsUnsupportedPlaylists(string $playlist, string $message): void {
		$this->expectException(MediaException::class);
		$this->expectExceptionMessage($message);
		HlsPlaylist::parseMedia($playlist);
	}

	public static function rejectedPlaylists(): array {
		$head = "#EXTM3U\n#EXT-X-TARGETDURATION:4\n";
		return [
			'encrypted' => [$head . "#EXT-X-KEY:METHOD=AES-128,URI=\"k\"\n#EXTINF:4,\na.ts\n#EXT-X-ENDLIST\n", 'already encrypted'],
			'fmp4' => [$head . "#EXT-X-MAP:URI=\"init.mp4\"\n#EXTINF:4,\na.m4s\n#EXT-X-ENDLIST\n", 'fMP4'],
			'live' => [$head . "#EXTINF:4,\na.ts\n", 'ENDLIST'],
			'url' => [$head . "#EXTINF:4,\nhttps://cdn.example.com/a.ts\n#EXT-X-ENDLIST\n", 'relative'],
			'absolute' => [$head . "#EXTINF:4,\n/etc/passwd\n#EXT-X-ENDLIST\n", 'relative'],
			'not hls' => ["hello\n", 'not an HLS playlist'],
		];
	}

	public function testRejectsSeparateAudioRenditions(): void {
		$this->expectException(MediaException::class);
		HlsPlaylist::parseMaster("#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"a\",URI=\"audio.m3u8\"\n#EXT-X-STREAM-INF:BANDWIDTH=1,AUDIO=\"a\"\nv.m3u8\n");
	}

	public function testBuildsEncryptedMediaPlaylist(): void {
		$playlist = HlsPlaylist::buildMedia(4, 0, [['duration' => 4.0, 'uri' => 'v0_00000.ts'], ['duration' => 3.5, 'uri' => 'v0_00001.ts']]);

		$this->assertStringContainsString("#EXT-X-KEY:METHOD=AES-128,URI=\"screenings-key\"\n", $playlist);
		$this->assertStringContainsString("#EXTINF:4,\nv0_00000.ts\n#EXTINF:3.5,\nv0_00001.ts\n#EXT-X-ENDLIST\n", $playlist);
		// The output must itself be a valid input for the parser.
		$this->assertCount(2, HlsPlaylist::parseMedia(str_replace('#EXT-X-KEY:METHOD=AES-128', '#EXT-X-KEY:METHOD=NONE', $playlist))['segments']);
	}

	public function testSegmentEncryptionRoundTrips(): void {
		$key = random_bytes(16);
		$data = random_bytes(188 * 100 + 7);
		$iv = HlsPlaylist::iv(5);

		$this->assertSame(16, strlen($iv));
		$this->assertSame("\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\x05", $iv);
		$encrypted = openssl_encrypt($data, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
		$this->assertSame(0, strlen($encrypted) % 16);
		$this->assertSame($data, openssl_decrypt($encrypted, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv));
	}

	public function testResolvesRelativePaths(): void {
		$this->assertSame('/Films/HLS/v0/seg.ts', HlsPackager::resolve('/Films/HLS', 'v0/seg.ts'));
		$this->assertSame('/Films/seg.ts', HlsPackager::resolve('/Films/HLS/v0', '../../seg.ts'));
		$this->assertSame('/Films/a b.ts', HlsPackager::resolve('/Films', 'a%20b.ts?x=1'));
	}

	public function testRefusesPathsAboveTheUserRoot(): void {
		$this->expectException(MediaException::class);
		HlsPackager::resolve('/Films', '../../etc/passwd');
	}

	public function testRenditionLadder(): void {
		$this->assertSame([1080, 720, 480], array_keys(Transcoder::renditions(2160)));
		$this->assertSame([720, 480], array_keys(Transcoder::renditions(720)));
		$this->assertSame([480], array_keys(Transcoder::renditions(576)));
		$this->assertSame([360], array_keys(Transcoder::renditions(361)));
		$this->assertSame([720], array_keys(Transcoder::renditions(null)));
	}
}

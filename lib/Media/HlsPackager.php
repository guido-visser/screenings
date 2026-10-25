<?php

declare(strict_types=1);

namespace OCA\Screenings\Media;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Encrypts an unencrypted HLS export with AES-128 into app data and writes
 * rewritten playlists. Used for "Pre-packaged HLS" (input from the owner's
 * Files) and for "Server transcode" (input from ffmpeg's temporary output),
 * so both modes get the same encryption: one key per screening and a
 * distinct IV per segment (its media sequence number).
 *
 * Nothing is decoded, so this needs no ffmpeg and little CPU.
 */
class HlsPackager {
	/**
	 * @param string $masterPath path of the master (or single media) playlist, as understood by $read
	 * @param callable(string): string $read returns a file's contents by path
	 * @param callable(float): void $onProgress receives 0..1
	 * @return int duration in milliseconds
	 * @throws MediaException
	 */
	public function package(string $masterPath, callable $read, ISimpleFolder $out, string $key, callable $onProgress): int {
		$masterDir = dirname($masterPath);
		$variants = HlsPlaylist::parseMaster($read($masterPath));

		// A media playlist on its own becomes a single-variant master.
		if ($variants === null) {
			$sources = [['path' => $masterPath, 'attributes' => null]];
		} else {
			$sources = array_map(fn (array $variant) => [
				'path' => self::resolve($masterDir, $variant['uri']),
				'attributes' => $variant['attributes'],
			], $variants);
		}

		$parsed = [];
		$totalSegments = 0;
		foreach ($sources as $i => $source) {
			$parsed[$i] = HlsPlaylist::parseMedia($read($source['path']));
			$totalSegments += count($parsed[$i]['segments']);
		}

		$done = 0;
		$outVariants = [];
		$durationMs = 0;
		foreach ($sources as $i => $source) {
			$playlist = $parsed[$i];
			$dir = dirname($source['path']);
			$outSegments = [];
			$bytes = 0;
			foreach ($playlist['segments'] as $j => $segment) {
				$data = $read(self::resolve($dir, $segment['uri']));
				$bytes += strlen($data);
				$encrypted = openssl_encrypt($data, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, HlsPlaylist::iv($playlist['mediaSequence'] + $j));
				if ($encrypted === false) {
					throw new MediaException('Encrypting a segment failed');
				}
				$name = sprintf('v%d_%05d.ts', $i, $j);
				$out->newFile($name, $encrypted);
				$outSegments[] = ['duration' => $segment['duration'], 'uri' => $name];
				$onProgress(++$done / $totalSegments);
			}

			$playlistName = sprintf('v%d.m3u8', $i);
			$out->newFile($playlistName, HlsPlaylist::buildMedia($playlist['targetDuration'], $playlist['mediaSequence'], $outSegments));

			$variantMs = HlsPlaylist::totalDurationMs($playlist['segments']);
			$durationMs = max($durationMs, $variantMs);
			$attributes = $source['attributes']
				?? 'BANDWIDTH=' . max(1, (int)round($bytes * 8 / max(0.001, $variantMs / 1000)));
			$outVariants[] = ['uri' => $playlistName, 'attributes' => $attributes];
		}

		$out->newFile(MediaStorage::MASTER_PLAYLIST, HlsPlaylist::buildMaster($outVariants));
		return $durationMs;
	}

	/**
	 * Reads files from a user's Files by path relative to their root.
	 *
	 * @return callable(string): string
	 */
	public static function userFolderReader(Folder $userFolder): callable {
		return function (string $path) use ($userFolder): string {
			try {
				$node = $userFolder->get($path);
			} catch (NotFoundException) {
				throw new MediaException('Missing file referenced by the playlist: ' . $path);
			}
			if (!$node instanceof File) {
				throw new MediaException('Not a file: ' . $path);
			}
			return $node->getContent();
		};
	}

	/**
	 * Reads files from a local directory (ffmpeg output), by path relative to it.
	 *
	 * @return callable(string): string
	 */
	public static function localReader(string $root): callable {
		$root = rtrim($root, '/');
		return function (string $path) use ($root): string {
			$data = @file_get_contents($root . $path);
			if ($data === false) {
				throw new MediaException('Missing transcoder output: ' . $path);
			}
			return $data;
		};
	}

	/**
	 * Joins a playlist-relative URI onto a directory and normalises "." and
	 * "..", refusing to climb above the root.
	 *
	 * @throws MediaException
	 */
	public static function resolve(string $dir, string $uri): string {
		$uri = rawurldecode(explode('?', $uri, 2)[0]);
		$parts = [];
		foreach (explode('/', trim($dir, '/') . '/' . $uri) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}
			if ($part === '..') {
				if ($parts === []) {
					throw new MediaException('A playlist entry points outside your files');
				}
				array_pop($parts);
				continue;
			}
			$parts[] = $part;
		}
		return '/' . implode('/', $parts);
	}
}

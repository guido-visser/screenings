<?php

declare(strict_types=1);

namespace OCA\Screenings\Service;

/**
 * Recognises subtitle files that belong to a video by name, the way media
 * players do: "film.srt", "film.en.srt", "film.nl.forced.srt",
 * "film.English.sdh.srt", "film.eng.srt", "film_pt-BR.vtt".
 *
 * After the video's name come only a language and flags. Any other word
 * means the file belongs to something else ("film-trailer.en.srt" is not a
 * subtitle of "film.mp4").
 */
class SubtitleFileName {
	public const EXTENSIONS = ['srt', 'vtt'];
	/** Language code for subtitles whose file name names no language. */
	public const UNKNOWN_LANGUAGE = 'und';

	/** Flags media players use; "hi" is only a flag after a language (alone it is Hindi). */
	private const FLAGS = [
		'forced' => 'forced',
		'sdh' => 'sdh', 'cc' => 'sdh', 'hi' => 'sdh',
		'default' => 'default',
	];

	/** ISO 639-2 (bibliographic and terminology) codes for languages that have a 639-1 code. */
	private const ISO_639_2 = [
		'afr' => 'af', 'ara' => 'ar', 'bul' => 'bg', 'cat' => 'ca', 'ces' => 'cs', 'cze' => 'cs',
		'chi' => 'zh', 'zho' => 'zh', 'dan' => 'da', 'deu' => 'de', 'ger' => 'de', 'dut' => 'nl',
		'nld' => 'nl', 'ell' => 'el', 'gre' => 'el', 'eng' => 'en', 'est' => 'et', 'fas' => 'fa',
		'per' => 'fa', 'fin' => 'fi', 'fra' => 'fr', 'fre' => 'fr', 'fry' => 'fy', 'heb' => 'he',
		'hin' => 'hi', 'hrv' => 'hr', 'hun' => 'hu', 'ice' => 'is', 'isl' => 'is', 'ind' => 'id',
		'ita' => 'it', 'jpn' => 'ja', 'kor' => 'ko', 'lav' => 'lv', 'lit' => 'lt', 'msa' => 'ms',
		'may' => 'ms', 'nob' => 'nb', 'nno' => 'nn', 'nor' => 'no', 'pol' => 'pl', 'por' => 'pt',
		'ron' => 'ro', 'rum' => 'ro', 'rus' => 'ru', 'slk' => 'sk', 'slo' => 'sk', 'slv' => 'sl',
		'spa' => 'es', 'srp' => 'sr', 'swe' => 'sv', 'tha' => 'th', 'tur' => 'tr', 'ukr' => 'uk',
		'vie' => 'vi', 'wel' => 'cy', 'cym' => 'cy', 'eus' => 'eu', 'baq' => 'eu', 'glg' => 'gl',
	];

	/** Languages whose names (English and native) are recognised, e.g. "film.Nederlands.srt". */
	private const NAMED_LANGUAGES = [
		'af', 'ar', 'bg', 'ca', 'cs', 'cy', 'da', 'de', 'el', 'en', 'es', 'et', 'eu', 'fa', 'fi', 'fr',
		'fy', 'gl', 'he', 'hi', 'hr', 'hu', 'id', 'is', 'it', 'ja', 'ko', 'lt', 'lv', 'ms', 'nb', 'nl',
		'nn', 'no', 'pl', 'pt', 'ro', 'ru', 'sk', 'sl', 'sr', 'sv', 'th', 'tr', 'uk', 'vi', 'zh',
	];
	/** Names intl doesn't give or gives differently, and the most common ones for servers without intl. */
	private const EXTRA_NAMES = [
		'dutch' => 'nl', 'nederlands' => 'nl', 'flemish' => 'nl', 'vlaams' => 'nl',
		'english' => 'en', 'german' => 'de', 'deutsch' => 'de', 'french' => 'fr', 'français' => 'fr', 'francais' => 'fr',
		'spanish' => 'es', 'español' => 'es', 'espanol' => 'es', 'italian' => 'it', 'italiano' => 'it',
		'portuguese' => 'pt', 'português' => 'pt', 'portugues' => 'pt', 'brazilian' => 'pt-br', 'farsi' => 'fa',
	];

	/** @var ?array<string, string> */
	private static ?array $names = null;

	/**
	 * @return ?array{lang: string, label: string, forced: bool, sdh: bool, default: bool}
	 *               null when the file isn't a subtitle of this video
	 */
	public static function match(string $videoName, string $subtitleName): ?array {
		$extension = strtolower(pathinfo($subtitleName, PATHINFO_EXTENSION));
		if (!in_array($extension, self::EXTENSIONS, true)) {
			return null;
		}
		$base = pathinfo($videoName, PATHINFO_FILENAME);
		$name = substr($subtitleName, 0, -(strlen($extension) + 1));
		$baseLength = strlen($base);

		if (strcasecmp($name, $base) === 0) {
			$tokens = [];
		} elseif (strlen($name) > $baseLength + 1 && strncasecmp($name, $base, $baseLength) === 0
			&& in_array($name[$baseLength], ['.', '_', '-', ' '], true)) {
			// "film - fr.srt": a run of separators may follow the name.
			$rest = ltrim(substr($name, $baseLength), ' ._-');
			$tokens = array_values(array_filter(explode('.', $rest), fn (string $t) => $t !== ''));
		} else {
			return null;
		}

		$lang = null;
		$flags = ['forced' => false, 'sdh' => false, 'default' => false];
		foreach ($tokens as $token) {
			$lower = strtolower($token);
			$flag = self::FLAGS[$lower] ?? null;
			if ($flag !== null && ($lower !== 'hi' || $lang !== null)) {
				$flags[$flag] = true;
				continue;
			}
			$code = $lang === null ? self::language($token) : null;
			if ($code === null) {
				return null;
			}
			$lang = $code;
		}

		$lang ??= self::UNKNOWN_LANGUAGE;
		$label = $lang === self::UNKNOWN_LANGUAGE ? 'Subtitles' : self::languageName($lang);
		$suffixes = array_merge($flags['sdh'] ? ['SDH'] : [], $flags['forced'] ? ['forced'] : []);
		if ($suffixes !== []) {
			$label .= ' (' . implode(', ', $suffixes) . ')';
		}
		return ['lang' => $lang, 'label' => mb_substr($label, 0, 64)] + $flags;
	}

	/**
	 * A language code ("nl", "pt-br") for a token like "nl", "NLD", "pt-BR",
	 * "pt_BR" or "Nederlands", or null if it isn't one.
	 */
	public static function language(string $token): ?string {
		$lower = strtolower(trim($token));
		if (preg_match('/^([a-z]{2,3})(?:[-_]([a-z]{2}|[0-9]{3}))?$/', $lower, $m)) {
			$primary = self::ISO_639_2[$m[1]] ?? $m[1];
			if (self::isKnown($primary)) {
				return $primary . (isset($m[2]) ? '-' . $m[2] : '');
			}
		}
		return self::names()[$lower] ?? null;
	}

	/** "Nederlands", "English", "Português (Brasil)": the language in itself, as players show it. */
	public static function languageName(string $code): string {
		[$primary] = explode('-', $code);
		if (!class_exists(\Locale::class)) {
			return strtoupper($code);
		}
		$name = \Locale::getDisplayName(str_replace('-', '_', $code), $primary);
		if ($name === '' || strcasecmp($name, $code) === 0) {
			return strtoupper($code);
		}
		return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
	}

	private static function isKnown(string $code): bool {
		if (in_array($code, self::NAMED_LANGUAGES, true)) {
			return true;
		}
		// Other valid codes: intl names them (unknown codes come back as the code itself).
		return strlen($code) === 2 && class_exists(\Locale::class)
			&& strcasecmp(\Locale::getDisplayLanguage($code, 'en'), $code) !== 0;
	}

	/**
	 * @return array<string, string> lowercase language name (English or native) => code
	 */
	private static function names(): array {
		if (self::$names !== null) {
			return self::$names;
		}
		$names = self::EXTRA_NAMES;
		if (class_exists(\Locale::class)) {
			foreach (self::NAMED_LANGUAGES as $code) {
				foreach (['en', $code] as $in) {
					$name = mb_strtolower(\Locale::getDisplayLanguage($code, $in));
					if ($name !== '' && $name !== $code) {
						$names[$name] ??= $code;
					}
				}
			}
		}
		return self::$names = $names;
	}
}

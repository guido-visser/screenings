<?php

declare(strict_types=1);

namespace OCA\Screenings\Mail;

use OCA\Screenings\Exception\ValidationException;

/**
 * A small Mustache-like template language for the invitation email:
 *
 *   {{name}}                     the value, HTML-escaped (line breaks become <br>)
 *   {{#expires}}…{{/expires}}    shown only when the value is set
 *   {{^password}}…{{/password}}  shown only when the value is empty
 *
 * Values are always escaped: placeholders can't inject HTML, whoever
 * entered the value (a viewer's name, the owner's personal message).
 * Unknown placeholders render as nothing; unknownNames() lists them so
 * the editor can warn about typos.
 */
class TemplateRenderer {
	private const NAME = '[a-z][a-z0-9_]*';

	/**
	 * @param array<string, string|bool|null> $values
	 * @param bool $html false for plain text (the subject): nothing is escaped
	 * @throws ValidationException when sections don't match up
	 */
	public static function render(string $template, array $values, bool $html = true): string {
		self::validate($template);
		$out = self::sections($template, $values);
		return preg_replace_callback('/\{\{\s*(' . self::NAME . ')\s*\}\}/i', function (array $m) use ($values, $html): string {
			$value = $values[strtolower($m[1])] ?? '';
			$value = is_bool($value) ? '' : (string)$value;
			return $html ? nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) : $value;
		}, $out);
	}

	/**
	 * Placeholders and sections that aren't among $known.
	 *
	 * @param string[] $known
	 * @return string[]
	 */
	public static function unknownNames(string $template, array $known): array {
		preg_match_all('/\{\{\s*[#^\/]?\s*(' . self::NAME . ')\s*\}\}/i', $template, $m);
		$names = array_unique(array_map('strtolower', $m[1]));
		return array_values(array_diff($names, $known));
	}

	/**
	 * @throws ValidationException
	 */
	public static function validate(string $template): void {
		preg_match_all('/\{\{\s*([#^\/])\s*(' . self::NAME . ')\s*\}\}/i', $template, $m, PREG_SET_ORDER);
		$open = [];
		foreach ($m as [, $kind, $name]) {
			$name = strtolower($name);
			if ($kind !== '/') {
				$open[] = $name;
			} elseif (array_pop($open) !== $name) {
				throw new ValidationException(sprintf('{{/%s}} doesn\'t close the section opened before it', $name), 'html');
			}
		}
		if ($open !== []) {
			throw new ValidationException(sprintf('{{#%s}} is never closed with {{/%s}}', end($open), end($open)), 'html');
		}
	}

	/**
	 * Resolves sections from the inside out, so they can be nested.
	 */
	private static function sections(string $template, array $values): string {
		$pattern = '/\{\{\s*([#^])\s*(' . self::NAME . ')\s*\}\}((?:(?!\{\{\s*[#^]\s*\2\s*\}\}).)*?)\{\{\s*\/\s*\2\s*\}\}/is';
		do {
			$template = preg_replace_callback($pattern, function (array $m) use ($values): string {
				$value = $values[strtolower($m[2])] ?? null;
				$set = $value !== null && $value !== false && $value !== '';
				return ($m[1] === '#') === $set ? $m[3] : '';
			}, $template, -1, $count);
		} while ($count > 0);
		return $template;
	}

	/**
	 * A plain-text version of an HTML email, for mail apps that don't show
	 * HTML: block elements become line breaks, links keep their address.
	 */
	public static function toText(string $html): string {
		$text = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $html);
		// As in a browser: line breaks and indentation in the source are just spaces.
		$text = preg_replace('/\s+/u', ' ', $text);
		$text = preg_replace_callback('#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function (array $m): string {
			$label = trim(strip_tags($m[3]));
			$url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
			return $label === '' || $label === $url ? $url : $label . ' (' . $url . ')';
		}, $text);
		$text = preg_replace('#<br\s*/?>#i', "\n", $text);
		$text = preg_replace('#</(p|div|h[1-6]|tr|table|li|blockquote)>#i', "\n\n", $text);
		$text = preg_replace('#<li\b[^>]*>#i', '- ', $text);
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$lines = array_map('trim', explode("\n", str_replace("\r", '', $text)));
		return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
	}

	/**
	 * Removes what has no place in an email and only harms deliverability
	 * or safety: scripts, embedded frames and inline event handlers.
	 */
	public static function clean(string $html): string {
		$html = preg_replace('#<(script|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html);
		$html = preg_replace('#<(script|iframe|object|embed)\b[^>]*/?>#i', '', $html);
		return preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\Tracking;

/**
 * Pure helpers for "which parts of the film were actually played", stored
 * as merged [start, end] intervals in seconds.
 */
class WatchRanges {
	/** Intervals closer than this are joined (rounding, segment boundaries). */
	public const JOIN_GAP = 1.0;

	/**
	 * Cleans client-reported intervals: numeric, inside [0, duration],
	 * non-empty. With $durationS <= 0 only the lower bound applies.
	 *
	 * @return list<array{float, float}>
	 */
	public static function sanitize(mixed $ranges, float $durationS): array {
		if (!is_array($ranges)) {
			return [];
		}
		$clean = [];
		foreach (array_slice($ranges, 0, 100) as $range) {
			if (!is_array($range) || count($range) !== 2 || !is_numeric($range[0] ?? null) || !is_numeric($range[1] ?? null)) {
				continue;
			}
			$start = max(0.0, (float)$range[0]);
			$end = (float)$range[1];
			if ($durationS > 0) {
				$end = min($end, $durationS);
			}
			if ($end > $start) {
				$clean[] = [$start, $end];
			}
		}
		return $clean;
	}

	/**
	 * @param list<array{float, float}> $ranges
	 */
	public static function totalSeconds(array $ranges): float {
		return array_sum(array_map(fn (array $r) => $r[1] - $r[0], $ranges));
	}

	/**
	 * Union of interval lists, sorted, with touching or nearly touching
	 * intervals joined.
	 *
	 * @param list<array{float, float}> ...$lists
	 * @return list<array{float, float}>
	 */
	public static function merge(array ...$lists): array {
		$all = array_merge(...$lists);
		usort($all, fn (array $a, array $b) => $a[0] <=> $b[0]);
		$merged = [];
		foreach ($all as [$start, $end]) {
			$last = count($merged) - 1;
			if ($last >= 0 && $start <= $merged[$last][1] + self::JOIN_GAP) {
				$merged[$last][1] = max($merged[$last][1], $end);
			} else {
				$merged[] = [$start, $end];
			}
		}
		return $merged;
	}
}

<?php

declare(strict_types=1);

namespace OCA\Screenings\Dashboard;

/**
 * Splits a film into equal time buckets and counts, per bucket, how many
 * interval lists cover it (lists are viewers for the screening heatmap, or
 * sessions for one viewer's "watched how often" heatmap).
 */
class Heatmap {
	public const MAX_BUCKETS = 200;
	/** A bucket counts as watched when at least this share of it was played. */
	public const COVER_SHARE = 0.5;

	/**
	 * Bucket length: whole seconds, at most MAX_BUCKETS buckets.
	 */
	public static function bucketSeconds(float $durationS): int {
		return max(1, (int)ceil($durationS / self::MAX_BUCKETS));
	}

	/**
	 * @param list<list<array{float, float}>> $rangeLists each list merged and sorted
	 * @return list<int> count per bucket
	 */
	public static function buckets(array $rangeLists, float $durationS): array {
		if ($durationS <= 0) {
			return [];
		}
		$size = self::bucketSeconds($durationS);
		$count = (int)ceil($durationS / $size);
		$buckets = array_fill(0, $count, 0);

		foreach ($rangeLists as $ranges) {
			$covered = array_fill(0, $count, 0.0);
			foreach ($ranges as [$start, $end]) {
				$first = max(0, (int)floor($start / $size));
				$last = min($count - 1, (int)floor(max($start, $end - 0.0001) / $size));
				for ($i = $first; $i <= $last; $i++) {
					$overlap = min($end, ($i + 1) * $size) - max($start, $i * $size);
					$covered[$i] += max(0.0, $overlap);
				}
			}
			foreach ($covered as $i => $seconds) {
				// The last bucket may be shorter than the others.
				$length = min($size, $durationS - $i * $size);
				if ($length > 0 && $seconds >= $length * self::COVER_SHARE) {
					$buckets[$i]++;
				}
			}
		}
		return $buckets;
	}
}

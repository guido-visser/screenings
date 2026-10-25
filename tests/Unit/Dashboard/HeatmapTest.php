<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Dashboard;

use OCA\Screenings\Dashboard\Heatmap;
use PHPUnit\Framework\TestCase;

class HeatmapTest extends TestCase {
	public function testBucketSize(): void {
		$this->assertSame(1, Heatmap::bucketSeconds(20));
		$this->assertSame(1, Heatmap::bucketSeconds(200));
		$this->assertSame(2, Heatmap::bucketSeconds(201));
		$this->assertSame(36, Heatmap::bucketSeconds(7200));
	}

	public function testCountsViewersPerBucket(): void {
		// 10 s film, 1 s buckets. Viewer A watched 0-5, viewer B 3-10.
		$buckets = Heatmap::buckets([[[0.0, 5.0]], [[3.0, 10.0]]], 10.0);

		$this->assertSame([1, 1, 1, 2, 2, 1, 1, 1, 1, 1], $buckets);
	}

	public function testPartlyWatchedBucketNeedsHalf(): void {
		// 0.4 s into bucket 1 doesn't count, 0.6 s into bucket 2 does.
		$this->assertSame([1, 0, 0, 0], Heatmap::buckets([[[0.0, 1.4]]], 4.0));
		$this->assertSame([0, 0, 1, 0], Heatmap::buckets([[[2.0, 2.6]]], 4.0));
	}

	public function testSkippedMiddleStaysEmpty(): void {
		$buckets = Heatmap::buckets([[[0.0, 2.0], [8.0, 10.0]]], 10.0);

		$this->assertSame([1, 1, 0, 0, 0, 0, 0, 0, 1, 1], $buckets);
	}

	public function testShortLastBucket(): void {
		// 401 s film -> 3 s buckets, the last one only 2 s long.
		$buckets = Heatmap::buckets([[[399.0, 401.0]]], 401.0);

		$this->assertCount(134, $buckets);
		$this->assertSame(1, $buckets[133]);
	}

	public function testNoDuration(): void {
		$this->assertSame([], Heatmap::buckets([[[0.0, 5.0]]], 0));
	}
}

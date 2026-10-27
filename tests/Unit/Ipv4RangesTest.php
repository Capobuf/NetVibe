<?php

namespace Tests\Unit;

use App\Support\Ipv4Ranges;
use PHPUnit\Framework\TestCase;

class Ipv4RangesTest extends TestCase
{
    public function test_overlaps_are_merged_without_combining_separate_intervals(): void
    {
        $this->assertSame([[100, 180], [181, 200]], Ipv4Ranges::merge([
            [140, 180], [181, 200], [100, 150], [110, 120],
        ]));
        $this->assertSame([[0, 4294967295]], Ipv4Ranges::merge([
            [0, 2147483648], [2147483648, 4294967295],
        ]));
    }

    public function test_removing_a_portion_preserves_both_sides_and_other_ranges(): void
    {
        $this->assertSame([[100, 119], [141, 180], [200, 210]], Ipv4Ranges::subtract([
            [100, 180], [200, 210],
        ], 120, 140));
        $this->assertSame([[100, 119], [206, 210]], Ipv4Ranges::subtract([
            [100, 180], [200, 210],
        ], 120, 205));
        $this->assertSame([], Ipv4Ranges::subtract([[0, 4294967295]], 0, 4294967295));
        $this->assertSame([[1, 4294967295]], Ipv4Ranges::subtract([[0, 4294967295]], 0, 0));
        $this->assertSame([[0, 4294967294]], Ipv4Ranges::subtract([[0, 4294967295]], 4294967295, 4294967295));
    }

    public function test_first_available_skips_documented_and_excluded_addresses_and_respects_usable_boundaries(): void
    {
        $this->assertSame(1, Ipv4Ranges::firstAvailable(0, 255, []));
        $this->assertSame(130, Ipv4Ranges::firstAvailable(0, 255, [
            [1, 1], [2, 128], [10, 10], [129, 129], [200, 210],
        ]));
        $this->assertNull(Ipv4Ranges::firstAvailable(0, 255, [[1, 254]]));
        $this->assertSame(4294967294, Ipv4Ranges::firstAvailable(4294967294, 4294967295, []));
        $this->assertSame(4294967295, Ipv4Ranges::firstAvailable(4294967294, 4294967295, [[4294967294, 4294967294]]));
        $this->assertNull(Ipv4Ranges::firstAvailable(4294967294, 4294967295, [[4294967294, 4294967295]]));
        $this->assertSame(4294967295, Ipv4Ranges::firstAvailable(4294967295, 4294967295, []));
        $this->assertNull(Ipv4Ranges::firstAvailable(4294967295, 4294967295, [[4294967295, 4294967295]]));
        $this->assertSame(0, Ipv4Ranges::firstAvailable(0, 0, []));
        $this->assertNull(Ipv4Ranges::firstAvailable(0, 4294967295, [[1, 4294967294]]));
    }
}

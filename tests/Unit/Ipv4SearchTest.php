<?php

namespace Tests\Unit;

use App\Support\Ipv4Ranges;
use App\Support\Ipv4Search;
use PHPUnit\Framework\TestCase;

class Ipv4SearchTest extends TestCase
{
    public function test_virtual_pages_respect_usable_boundaries_including_31_and_32(): void
    {
        $search = new Ipv4Search('');
        [$first, $last] = Ipv4Ranges::usableBounds(ip2long('172.16.0.0'), ip2long('172.16.255.255'));

        $this->assertSame(65534, $search->count($first, $last));
        $this->assertSame(range($first + 50, $first + 99), $search->slice($first, $last, 50, 50));
        $this->assertSame(range(ip2long('172.16.255.221'), $last), $search->slice($first, $last, 65500, 50));
        $this->assertSame(range($last, $last - 49), $search->slice($first, $last, 0, 50, true));
        $this->assertSame([], $search->slice($first, $last, 65534, 50));
        [$first, $last] = Ipv4Ranges::usableBounds(4294967294, 4294967295);
        $this->assertSame([4294967294, 4294967295], $search->slice($first, $last, 0, 50));
        [$first, $last] = Ipv4Ranges::usableBounds(4294967295, 4294967295);
        $this->assertSame([4294967295], $search->slice($first, $last, 0, 50));
        $this->assertSame([0], $search->slice(0, 0, 0, 50));
    }

    public function test_internal_fragments_count_and_paginate_without_duplicate_matches(): void
    {
        $first = ip2long('172.16.70.0');
        $last = ip2long('172.16.73.255');
        foreach (['.72.23', '1', '16.7', '172.16.72.23', '72.', 'hostname', '%', '172.016', '172.16.72.230000'] as $fragment) {
            $expected = array_values(array_filter(range($first, $last), fn ($address) => str_contains(long2ip($address), $fragment)));
            $search = new Ipv4Search($fragment);

            $this->assertSame(count($expected), $search->count($first, $last), $fragment);
            $this->assertSame(array_slice($expected, 3, 50), $search->slice($first, $last, 3, 50), $fragment);
            $this->assertSame(array_slice(array_reverse($expected), 3, 50), $search->slice($first, $last, 3, 50, true), $fragment);
        }
        $search = new Ipv4Search('.72.23');
        $this->assertSame(1441792, $search->count(0, 4294967295));
        $this->assertSame([ip2long('172.16.72.23')], $search->slice($first, $last, 0, 1));
    }
}

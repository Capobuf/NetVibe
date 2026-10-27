<?php

namespace App\Support;

class Ipv4Search
{
    private array $counts = [];

    private array $transitions = [];

    public function __construct(private string $search) {}

    public function count(int $first, int $last): int
    {
        return $first > $last ? 0 : $this->countThrough($last) - $this->countThrough($first - 1);
    }

    /** @return list<int> */
    public function slice(int $first, int $last, int $offset, int $limit, bool $descending = false): array
    {
        $before = $this->countThrough($first - 1);
        $total = $this->count($first, $last);
        $addresses = [];
        for ($index = $offset; $index < min($total, $offset + $limit); $index++) {
            if ($this->search === '') {
                $addresses[] = $descending ? $last - $index : $first + $index;

                continue;
            }
            $rank = $before + ($descending ? $total - $index : $index + 1);
            $left = $first;
            $right = $last;
            while ($left < $right) {
                $middle = intdiv($left + $right, 2);
                if ($this->countThrough($middle) < $rank) {
                    $left = $middle + 1;
                } else {
                    $right = $middle;
                }
            }
            $addresses[] = $left;
        }

        return $addresses;
    }

    private function countThrough(int $last): int
    {
        if ($last < 0 || strlen($this->search) > 15 || preg_match('/[^0-9.]/', $this->search)) {
            return 0;
        }
        if ($this->search === '') {
            return $last + 1;
        }
        $total = 0;
        $matched = 0;
        for ($position = 0; $position < 4; $position++) {
            $octet = ($last >> ((3 - $position) * 8)) & 255;
            $totals = $this->octetCounts(3 - $position, $matched, $position > 0);
            $total += $totals[$octet];
            $matched = $this->advance($matched, ($position > 0 ? '.' : '').$octet);
        }

        return $total + (int) ($matched === strlen($this->search));
    }

    /** @return list<int> Counts for octets strictly below each index. */
    private function octetCounts(int $remaining, int $matched, bool $dot): array
    {
        $key = "$remaining:$matched:".(int) $dot;
        if (isset($this->counts[$key])) {
            return $this->counts[$key];
        }
        // Count shared suffixes once, so even a /0 search never enumerates its addresses.
        $totals = [0];
        for ($octet = 0; $octet < 256; $octet++) {
            $next = $this->advance($matched, ($dot ? '.' : '').$octet);
            $count = $next === strlen($this->search) ? 256 ** $remaining
                : ($remaining === 0 ? 0 : $this->octetCounts($remaining - 1, $next, true)[256]);
            $totals[] = $totals[$octet] + $count;
        }

        return $this->counts[$key] = $totals;
    }

    private function advance(int $matched, string $text): int
    {
        $length = strlen($this->search);
        if ($matched === $length) {
            return $matched;
        }
        $key = "$matched:$text";
        if (isset($this->transitions[$key])) {
            return $this->transitions[$key];
        }
        $suffix = substr($this->search, 0, $matched).$text;
        if (str_contains($suffix, $this->search)) {
            return $this->transitions[$key] = $length;
        }
        for ($size = min($length - 1, strlen($suffix)); $size > 0; $size--) {
            if (str_ends_with($suffix, substr($this->search, 0, $size))) {
                return $this->transitions[$key] = $size;
            }
        }

        return $this->transitions[$key] = 0;
    }
}

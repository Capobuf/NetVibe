<?php

namespace App\Support;

class Ipv4Ranges
{
    /** @return array{0: int, 1: int} */
    public static function usableBounds(int $network, int $broadcast): array
    {
        return $broadcast - $network > 1
            ? [$network + 1, $broadcast - 1]
            : [$network, $broadcast];
    }

    /** @param list<array{0: int, 1: int}> $ranges
     * @return list<array{0: int, 1: int}>
     */
    public static function merge(array $ranges): array
    {
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            $last = array_key_last($merged);
            if ($last !== null && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /** @param list<array{0: int, 1: int}> $ranges
     * @return list<array{0: int, 1: int}>
     */
    public static function subtract(array $ranges, int $start, int $end): array
    {
        $remaining = [];
        foreach ($ranges as [$left, $right]) {
            if ($right < $start || $left > $end) {
                $remaining[] = [$left, $right];

                continue;
            }
            if ($left < $start) {
                $remaining[] = [$left, $start - 1];
            }
            if ($right > $end) {
                $remaining[] = [$end + 1, $right];
            }
        }

        return $remaining;
    }

    /** @param iterable<array{0: int, 1: int}> $blocked Ranges ordered by start, including documented IPs as single-address ranges. */
    public static function firstAvailable(int $network, int $broadcast, iterable $blocked): ?int
    {
        [$candidate, $last] = self::usableBounds($network, $broadcast);
        foreach ($blocked as [$start, $end]) {
            if ($start > $candidate || $candidate > $last) {
                break;
            }
            $candidate = max($candidate, $end + 1);
        }

        return $candidate <= $last ? $candidate : null;
    }
}

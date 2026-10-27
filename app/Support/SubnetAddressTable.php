<?php

namespace App\Support;

use App\Models\Subnet;
use Illuminate\Pagination\LengthAwarePaginator;

class SubnetAddressTable
{
    public static function paginate(Subnet $subnet, string $search, ?string $sort, string $direction, int $page): LengthAwarePaginator
    {
        [$first, $last] = Ipv4Ranges::usableBounds($subnet->network_address, $subnet->broadcast_address);
        $matcher = new Ipv4Search($search);
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        $matching = $subnet->ipAddresses()->whereBetween('address', [$first, $last])
            ->when($search !== '', function ($query) use ($pattern) {
                $query->where(function ($query) use ($pattern) {
                    $query->whereRaw("INET_NTOA(address) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("hostname LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("mac_address LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
                });
            });
        $textSort = $sort !== null && $sort !== 'ip';
        $populated = $textSort ? (clone $matching)->whereNotNull($sort) : null;
        $populatedCount = $populated?->count() ?? 0;

        // The virtual group includes null metadata; only stored exceptions split its ranges.
        $exceptions = [];
        if ($populated !== null) {
            foreach ((clone $populated)->whereRaw("INET_NTOA(address) LIKE ? ESCAPE '!'", [$pattern])->pluck('address') as $address) {
                $exceptions[$address] = false;
            }
        }
        if ($search !== '') {
            $extra = (clone $matching)->whereRaw("INET_NTOA(address) NOT LIKE ? ESCAPE '!'", [$pattern])
                ->when($textSort, fn ($query) => $query->whereNull($sort));
            foreach ($extra->pluck('address') as $address) {
                $exceptions[$address] = true;
            }
        }
        ksort($exceptions, SORT_NUMERIC);
        $segments = [];
        $next = $first;
        foreach ($exceptions as $address => $include) {
            if ($next < $address) {
                $segments[] = [$next, $address - 1, $matcher->count($next, $address - 1), false];
            }
            if ($include) {
                $segments[] = [$address, $address, 1, true];
            }
            $next = $address + 1;
        }
        if ($next <= $last) {
            $segments[] = [$next, $last, $matcher->count($next, $last), false];
        }
        $virtualCount = array_sum(array_column($segments, 2));
        $total = $virtualCount + $populatedCount;
        $page = min($page, max(1, (int) ceil($total / 50)));
        $offset = ($page - 1) * 50;
        $addresses = [];
        $groups = $textSort && $direction === 'desc' ? ['populated', 'virtual'] : ['virtual', 'populated'];
        foreach ($groups as $group) {
            $count = $group === 'virtual' ? $virtualCount : $populatedCount;
            if ($offset >= $count) {
                $offset -= $count;

                continue;
            }
            $limit = 50 - count($addresses);
            if ($group === 'populated') {
                $items = (clone $populated)->orderBy($sort, $direction)->orderBy('address')
                    ->offset($offset)->limit($limit)->pluck('address')->all();
            } else {
                $items = self::slice($matcher, $segments, $offset, $limit, ! $textSort && $direction === 'desc');
            }
            $addresses = [...$addresses, ...$items];
            $offset = 0;
            if (count($addresses) === 50) {
                break;
            }
        }

        $records = $subnet->ipAddresses()->whereIn('address', $addresses)->get()->keyBy('address');
        $excludedRanges = $subnet->excludedRanges;
        $rows = array_map(function ($address) use ($records, $excludedRanges) {
            $record = $records->get($address);

            return [
                'id' => $record?->id,
                'ip' => long2ip($address),
                'hostname' => $record?->hostname,
                'mac_address' => $record?->mac_address,
                'description' => $record?->description,
                'excluded' => $excludedRanges->contains(fn ($range) => $range->start_address <= $address && $range->end_address >= $address),
            ];
        }, $addresses);

        return (new LengthAwarePaginator($rows, $total, 50, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]))->withQueryString();
    }

    /** @param list<array{0: int, 1: int, 2: int, 3: bool}> $segments
     * @return list<int>
     */
    private static function slice(Ipv4Search $matcher, array $segments, int $offset, int $limit, bool $descending): array
    {
        $addresses = [];
        foreach ($descending ? array_reverse($segments) : $segments as [$first, $last, $count, $included]) {
            if ($offset >= $count) {
                $offset -= $count;

                continue;
            }
            $addresses = [...$addresses, ...($included ? [$first]
                : $matcher->slice($first, $last, $offset, $limit - count($addresses), $descending))];
            $offset = 0;
            if (count($addresses) === $limit) {
                break;
            }
        }

        return $addresses;
    }
}

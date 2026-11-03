<?php

namespace App\Support;

use App\Models\Subnet;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SubnetAddressTable
{
    public static function paginate(Subnet $subnet, string $search, ?string $sort, string $direction, int $page, int $perPage = 25, bool $hideFree = false, ?int $focusAddress = null): LengthAwarePaginator
    {
        [$first, $last] = Ipv4Ranges::usableBounds($subnet->network_address, $subnet->broadcast_address);
        $matcher = new Ipv4Search($search);
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        $sourceMatches = UnifiIpam::addressSources($subnet->site_id)->whereBetween('address', [$first, $last])
            ->whereRaw("CAST(data AS CHAR) LIKE ? ESCAPE '!'", [$pattern])->select('address')->distinct();
        $matching = $subnet->ipAddresses()->whereBetween('address', [$first, $last])
            ->when($search !== '', function ($query) use ($pattern, $sourceMatches) {
                $query->where(function ($query) use ($pattern, $sourceMatches) {
                    $query->whereRaw("INET_NTOA(address) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("hostname LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("mac_address LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw(DeviceAssignment::labelSql()." LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereIn('address', $sourceMatches)
                        ->orWhereHas('snipeItAsset', fn ($query) => $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.name')) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.network.mac')) LIKE ? ESCAPE '!'", [$pattern]))
                        ->orWhereHas('status', fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]))
                        ->orWhereHas('tags', fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                });
            });
        $textSort = $sort !== null && $sort !== 'ip';
        $relatedSort = in_array($sort, ['status', 'tags', 'device'], true);
        $sortColumn = match ($sort) {
            'device' => 'assignment_label',
            'status' => 'status_name',
            'tags' => 'tags_min_name',
            default => $sort,
        };
        if ($sort === 'device') {
            $matching->select('ip_addresses.*')->selectRaw(DeviceAssignment::labelSql().' as assignment_label');
        } elseif ($sort === 'status') {
            $matching->withAggregate('status', 'name');
        } elseif ($sort === 'tags') {
            $matching->withMin('tags', 'name');
        }
        $populated = $textSort ? (clone $matching) : null;
        $sourceSort = in_array($sort, ['hostname', 'mac_address'], true);
        if ($sourceSort) {
            $field = $sort === 'hostname' ? 'hostname_observed' : 'mac';
            $observed = UnifiIpam::addressSources($subnet->site_id)->whereBetween('address', [$first, $last])
                ->selectRaw("address AS source_address, MIN(CASE WHEN JSON_TYPE(data->'$.{$field}') = 'STRING' THEN NULLIF(TRIM(data->>'$.{$field}'), '') END) AS sort_value")
                ->groupBy('address');
            $documented = (clone $matching)->leftJoinSub($observed, 'observed', fn ($join) => $join->on('ip_addresses.address', '=', 'observed.source_address'))
                ->select('ip_addresses.address')->selectRaw("COALESCE(NULLIF(ip_addresses.{$sort}, ''), observed.sort_value) AS sort_value");
            $external = (clone $observed)->whereNotIn('address', $subnet->ipAddresses()->select('address'))
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->whereRaw("INET_NTOA(address) LIKE ? ESCAPE '!'", [$pattern])->orWhereIn('address', $sourceMatches)));
            // Only addresses with metadata join this finite group; empty IPv4 ranges stay virtual.
            $populated = DB::query()->fromSub($documented->toBase()->unionAll($external), 'entries')->whereNotNull('sort_value');
            $sortColumn = 'sort_value';
        } elseif ($populated !== null) {
            $relatedSort ? $populated->has($sort) : $populated->whereNotNull($sort);
        }
        $populatedCount = $populated?->count() ?? 0;

        if ($hideFree) {
            $observed = UnifiIpam::addressSources($subnet->site_id)->whereBetween('address', [$first, $last])
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->whereRaw("INET_NTOA(address) LIKE ? ESCAPE '!'", [$pattern])->orWhereIn('address', $sourceMatches)))
                ->distinct()->pluck('address');
            $populatedAddresses = $populated ? (clone $populated)->pluck('address') : collect();
            $segments = (clone $matching)->pluck('address')->merge($observed)->unique()
                ->diff($populatedAddresses)->sort(SORT_NUMERIC)
                ->map(fn ($address) => [$address, $address, 1, true])->values()->all();
        } else {
            // The virtual group includes null metadata; only stored exceptions split its ranges.
            $exceptions = [];
            if ($populated !== null) {
                foreach ((clone $populated)->whereRaw("INET_NTOA(address) LIKE ? ESCAPE '!'", [$pattern])->pluck('address') as $address) {
                    $exceptions[$address] = false;
                }
            }
            if ($search !== '') {
                $extra = (clone $matching)->whereRaw("INET_NTOA(address) NOT LIKE ? ESCAPE '!'", [$pattern])
                    ->when($sourceSort, fn ($query) => $query->whereNotIn('address', (clone $populated)->select('address')))
                    ->when($textSort && ! $sourceSort, fn ($query) => $relatedSort ? $query->doesntHave($sort) : $query->whereNull($sort));
                foreach ($extra->pluck('address') as $address) {
                    $exceptions[$address] = true;
                }
            }
            if ($search !== '') {
                $populatedAddresses = $populated ? (clone $populated)->pluck('address')->all() : [];
                $external = (clone $sourceMatches)
                    ->whereRaw("INET_NTOA(address) NOT LIKE ? ESCAPE '!'", [$pattern])
                    ->whereNotIn('address', $populatedAddresses)->distinct()->pluck('address');
                foreach ($external as $address) {
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
        }
        $virtualCount = array_sum(array_column($segments, 2));
        $total = $virtualCount + $populatedCount;
        if ($focusAddress !== null) {
            $position = null;
            $before = 0;
            foreach ($segments as [$start, $end, $count, $included]) {
                if ($focusAddress >= $start && $focusAddress <= $end
                    && ($included || $matcher->count($focusAddress, $focusAddress) > 0)) {
                    $position = $before + ($included ? 0 : $matcher->count($start, $focusAddress) - 1);
                    if (! $textSort && $direction === 'desc') {
                        $position = $virtualCount - 1 - $position;
                    }
                    if ($textSort && $direction === 'desc') {
                        $position += $populatedCount;
                    }
                    break;
                }
                $before += $count;
            }
            if ($position === null && $populated !== null) {
                $order = $direction === 'desc' ? 'DESC' : 'ASC';
                $ranked = DB::query()->fromSub(clone $populated, 'entries')
                    ->select('address')->selectRaw("ROW_NUMBER() OVER (ORDER BY {$sortColumn} {$order}, address) AS row_position");
                $rank = DB::query()->fromSub($ranked, 'ranked')->where('address', $focusAddress)->value('row_position');
                if ($rank !== null) {
                    $position = (int) $rank - 1 + ($direction === 'asc' ? $virtualCount : 0);
                }
            }
            if ($position !== null) {
                $page = intdiv($position, $perPage) + 1;
            }
        }
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        $offset = ($page - 1) * $perPage;
        $addresses = [];
        $groups = $textSort && $direction === 'desc' ? ['populated', 'virtual'] : ['virtual', 'populated'];
        foreach ($groups as $group) {
            $count = $group === 'virtual' ? $virtualCount : $populatedCount;
            if ($offset >= $count) {
                $offset -= $count;

                continue;
            }
            $limit = $perPage - count($addresses);
            if ($group === 'populated') {
                $items = (clone $populated)->orderBy($sortColumn, $direction)->orderBy('address')
                    ->offset($offset)->limit($limit)->pluck('address')->all();
            } else {
                $items = self::slice($matcher, $segments, $offset, $limit, ! $textSort && $direction === 'desc');
            }
            $addresses = [...$addresses, ...$items];
            $offset = 0;
            if (count($addresses) === $perPage) {
                break;
            }
        }

        $records = $subnet->ipAddresses()->select('ip_addresses.*')->selectRaw(DeviceAssignment::labelSql().' as assignment_label')->with(['snipeItAsset.sync.connection', 'status:id,name', 'tags:id,name,color', 'device:id,name', 'deviceInterface:id,name,kind,connector_id,mac_address'])
            ->whereIn('address', $addresses)->get()->keyBy('address');
        $excludedRanges = $subnet->excludedRanges;
        $rows = array_map(function ($address) use ($records, $excludedRanges) {
            $record = $records->get($address);

            return [
                'id' => $record?->id,
                'mikrotik_edit_mode' => $record?->mikrotik_edit_mode,
                'mikrotik_matched_at' => $record?->mikrotik_matched_at,
                'assignment_label' => $record?->assignment_label,
                'ip' => long2ip($address),
                'device_id' => $record?->device_id, 'device_interface_id' => $record?->device_interface_id,
                'device' => $record?->device, 'device_interface' => $record?->deviceInterface,
                'snipe_it' => $record?->snipeItAsset?->ipamData(),
                'hostname' => $record?->hostname,
                'mac_address' => $record?->mac_address,
                'description' => $record?->description,
                'status' => $record?->status?->only('id', 'name'),
                'tags' => $record?->tags->map->only('id', 'name', 'color')->all() ?? [],
                'excluded' => $excludedRanges->contains(fn ($range) => $range->start_address <= $address && $range->end_address >= $address),
            ];
        }, $addresses);

        return (new LengthAwarePaginator($rows, $total, $perPage, $page, [
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

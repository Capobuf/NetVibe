<?php

namespace App\Models;

use App\Support\Ipv4Ranges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['cidr', 'network_address', 'broadcast_address', 'name', 'description', 'vlan_id'])]
class Subnet extends Model
{
    /** @return HasMany<ExcludedRange, $this> */
    public function excludedRanges(): HasMany
    {
        return $this->hasMany(ExcludedRange::class);
    }

    public function isExcluded(int $address): bool
    {
        return $this->excludedRanges()->where('start_address', '<=', $address)
            ->where('end_address', '>=', $address)->exists();
    }

    public function firstProposableIp(): ?string
    {
        $blocked = $this->ipAddresses()->selectRaw('address AS start_address, address AS end_address')->toBase()
            ->unionAll($this->excludedRanges()->select('start_address', 'end_address')->toBase())
            ->orderBy('start_address')->cursor()
            ->map(fn ($range) => [(int) $range->start_address, (int) $range->end_address]);
        $address = Ipv4Ranges::firstAvailable($this->network_address, $this->broadcast_address, $blocked);

        return $address === null ? null : long2ip($address);
    }

    /** @return array{documented: int, excluded: int, proposable: int} */
    public function addressCounts(): array
    {
        [$first, $last] = Ipv4Ranges::usableBounds($this->network_address, $this->broadcast_address);
        $documented = $this->ipAddresses()->whereBetween('address', [$first, $last])->count();
        $excluded = (int) $this->excludedRanges()
            ->where('start_address', '<=', $last)->where('end_address', '>=', $first)
            ->selectRaw('COALESCE(SUM(LEAST(end_address, ?) - GREATEST(start_address, ?) + 1), 0) AS total', [$last, $first])
            ->value('total');
        $documentedExcluded = $this->ipAddresses()->whereBetween('address', [$first, $last])
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('excluded_ranges')
                    ->whereColumn('excluded_ranges.subnet_id', 'ip_addresses.subnet_id')
                    ->whereColumn('excluded_ranges.start_address', '<=', 'ip_addresses.address')
                    ->whereColumn('excluded_ranges.end_address', '>=', 'ip_addresses.address');
            })->count();
        $excluded -= $documentedExcluded;

        return ['documented' => $documented, 'excluded' => $excluded, 'proposable' => $last - $first + 1 - $documented - $excluded];
    }

    /** @return HasMany<IpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /** @return BelongsTo<Vlan, $this> */
    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class);
    }
}

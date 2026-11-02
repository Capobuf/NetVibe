<?php

namespace App\Models;

use App\Support\Ipv4Ranges;
use App\Support\UnifiIpam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['cidr', 'network_address', 'broadcast_address', 'name', 'description', 'vlan_id'])]
class Subnet extends Model
{
    protected function casts(): array
    {
        return ['excluded_at' => 'datetime'];
    }

    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->whereNull('subnets.excluded_at')
            ->whereDoesntHave('vlan', fn ($vlan) => $vlan->whereNotNull('excluded_at'));
    }

    #[Scope]
    protected function excluded(Builder $query): void
    {
        $query->where(fn ($query) => $query->whereNotNull('subnets.excluded_at')
            ->orWhereHas('vlan', fn ($vlan) => $vlan->whereNotNull('excluded_at')));
    }

    /** @return HasMany<SubnetSummaryEntry, $this> */
    public function summaryEntries(): HasMany
    {
        return $this->hasMany(SubnetSummaryEntry::class);
    }

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
            ->unionAll(UnifiIpam::addressSources($this->site_id, true)->selectRaw('address AS start_address, address AS end_address'))
            ->unionAll($this->excludedRanges()->select('start_address', 'end_address')->toBase())
            ->orderBy('start_address')->cursor()
            ->map(fn ($range) => [(int) $range->start_address, (int) $range->end_address]);
        $address = Ipv4Ranges::firstAvailable($this->network_address, $this->broadcast_address, $blocked);

        return $address === null ? null : long2ip($address);
    }

    /** @return array{documented: int, excluded: int, external: int, proposable: int} */
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

        $external = UnifiIpam::addressSources($this->site_id, true)->whereBetween('address', [$first, $last])
            ->whereNotIn('address', $this->ipAddresses()->select('address'))
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('excluded_ranges')->where('subnet_id', $this->id)
                    ->whereColumn('start_address', '<=', 'observations.address')->whereColumn('end_address', '>=', 'observations.address');
            })->distinct()->count('address');

        return ['documented' => $documented, 'excluded' => $excluded, 'external' => $external, 'proposable' => $last - $first + 1 - $documented - $excluded - $external];
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

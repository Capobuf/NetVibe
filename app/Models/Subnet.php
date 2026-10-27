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

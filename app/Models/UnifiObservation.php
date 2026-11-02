<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['unifi_site_id', 'site_id', 'kind', 'source_key', 'state', 'data', 'anchor', 'address', 'vlan_id', 'subnet_id', 'ip_address_id', 'linked', 'problem', 'decision', 'edit_mode', 'expected', 'matched_at', 'seen_at'])]
class UnifiObservation extends Model
{
    protected function casts(): array
    {
        return ['data' => 'array', 'anchor' => 'array', 'expected' => 'array', 'matched_at' => 'datetime', 'address' => 'integer', 'linked' => 'boolean', 'seen_at' => 'datetime'];
    }

    public function unifiSite(): BelongsTo
    {
        return $this->belongsTo(UnifiSite::class);
    }

    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class);
    }

    public function subnet(): BelongsTo
    {
        return $this->belongsTo(Subnet::class);
    }

    public static function forSite(int $siteId): Builder
    {
        return static::query()->where('site_id', $siteId)->whereHas('unifiSite', fn ($query) => $query->where('site_id', $siteId));
    }

    public static function blocking(int $siteId): Builder
    {
        return static::forSite($siteId)->where('state', 'current')->whereNotNull('address')
            ->whereHas('unifiSite', fn ($query) => $query->whereNotNull('inventory_activated_at')->where('available', true)
                ->where('sync_status', 'ok')->whereHas('unifiConnection', fn ($connection) => $connection->where('enabled', true)->where('check_status', 'ok')));
    }

    public function stale(): bool
    {
        return $this->state !== 'current' || ! $this->unifiSite?->inventory_activated_at || ! $this->unifiSite->available
            || $this->unifiSite->sync_status !== 'ok' || ! $this->unifiSite->unifiConnection?->enabled || $this->unifiSite->unifiConnection->check_status !== 'ok';
    }

    public function display(): array
    {
        return [
            ...$this->only('id', 'kind', 'state', 'data', 'seen_at', 'problem', 'vlan_id', 'subnet_id', 'ip_address_id', 'decision', 'edit_mode', 'matched_at'),
            'local_values' => ['vlan_tag' => $this->vlan?->tag, 'vlan_name' => $this->vlan?->name, 'subnet_vlan_tag' => $this->subnet?->vlan?->tag],
            'source' => 'UniFi · '.$this->unifiSite->console_name.' · '.$this->unifiSite->name,
            'ip' => $this->address === null ? null : long2ip($this->address),
            'stale' => $this->stale(),
            'can_update' => ! $this->stale() && $this->kind === 'network' && $this->problem !== null
                && in_array($this->problem, ['Nome VLAN diverso dalla fonte.', 'VLAN documentata diversa dalla fonte.'], true),
        ];
    }
}

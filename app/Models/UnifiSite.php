<?php

namespace App\Models;

use App\Support\UnifiInventory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['console_id', 'console_name', 'external_site_id', 'name', 'version', 'available', 'preview', 'discovered_at', 'site_id', 'confirmed_at', 'mapped_site_uuid', 'inventory_activated_at', 'last_synced_at', 'sync_status', 'last_access'])]
class UnifiSite extends Model
{
    protected function casts(): array
    {
        return ['available' => 'boolean', 'preview' => 'array', 'discovered_at' => 'datetime', 'confirmed_at' => 'datetime', 'inventory_activated_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function unifiConnection(): BelongsTo
    {
        return $this->belongsTo(UnifiConnection::class, 'unifi_connection_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function unifiDevices(): HasMany
    {
        return $this->hasMany(UnifiDevice::class);
    }

    /**
     * Other readable accesses to the same UniFi site, the direct host first. A direct host
     * qualifies only when it belongs to the company of the linked NetVibe site.
     */
    public function alternatives(): Collection
    {
        $companyId = $this->site?->company_id;

        return static::where('external_site_id', $this->external_site_id)->whereKeyNot($this->id)->where('available', true)
            ->with('unifiConnection')->get()
            ->filter(fn (self $other) => $other->unifiConnection->enabled && ($other->unifiConnection->mode === 'cloud'
                ? in_array($other->console_id, $other->unifiConnection->selected_console_ids ?? [], true)
                : $companyId !== null && $other->unifiConnection->company_id === $companyId))
            ->sortBy(fn (self $other) => $other->unifiConnection->mode === 'direct' ? 0 : 1)->values();
    }

    /** Moves the link and the data acquired so far to another access of the same UniFi site. */
    public function transferTo(self $target): void
    {
        // Tombstones left on the target by an earlier mapping would collide with the moved identities.
        $target->unifiDevices()->delete();
        UnifiObservation::where('unifi_site_id', $target->id)->delete();
        $this->unifiDevices()->update(['unifi_site_id' => $target->id]);
        UnifiObservation::where('unifi_site_id', $this->id)->update(['unifi_site_id' => $target->id]);
        $link = $this->only('site_id', 'confirmed_at', 'inventory_activated_at', 'last_synced_at', 'sync_status', 'last_access');
        $this->update(['site_id' => null, 'confirmed_at' => null, 'mapped_site_uuid' => null, 'inventory_activated_at' => null,
            'last_synced_at' => null, 'sync_status' => null, 'last_access' => null]);
        $target->update([...$link, 'mapped_site_uuid' => $target->external_site_id]);
    }

    public function summary(?int $companyId = null): array
    {
        $via = $this->site_id ? null : static::where('mapped_site_uuid', $this->external_site_id)->whereKeyNot($this->id)
            ->whereNotNull('site_id')->with('site.company', 'unifiConnection')->first();
        // Inside a company, a link owned by another company is reported without naming it.
        $elsewhere = $via && $companyId !== null && $via->site->company_id !== $companyId;

        return [
            ...$this->only('id', 'console_id', 'console_name', 'external_site_id', 'name', 'version', 'available', 'preview', 'discovered_at', 'confirmed_at', 'last_access', 'sync_status', 'last_synced_at'),
            'inventory' => $this->site_id ? app(UnifiInventory::class)->summary($this) : null,
            'site' => $this->site ? [
                ...$this->site->only('id', 'name'), 'company' => $this->site->company->only('id', 'name'),
            ] : null,
            // The same UniFi site can be reachable through another connection that already feeds a NetVibe site.
            'linked_via' => $via && ! $elsewhere ? $via->linkedSummary() : null,
            'linked_elsewhere' => $elsewhere,
        ];
    }

    private function linkedSummary(): array
    {
        return [
            'connection' => $this->unifiConnection->name,
            'mode' => $this->unifiConnection->mode,
            'site' => [...$this->site->only('id', 'name'), 'company' => $this->site->company->only('id', 'name')],
        ];
    }
}

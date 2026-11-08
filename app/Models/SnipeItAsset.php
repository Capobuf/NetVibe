<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sync_id', 'external_id', 'snipe_it_model_id', 'device_id', 'ip_address_id', 'removed_address', 'snapshot', 'state', 'present', 'needs_confirmation', 'problem'])]
class SnipeItAsset extends Model
{
    // Views partition the assets: every asset belongs to exactly one of them.
    public const VIEWS = ['pending', 'linked', 'ipam', 'excluded'];

    #[Scope]
    protected function forView(Builder $query, string $view): void
    {
        if ($view === 'excluded') {
            $query->where('snipe_it_assets.state', 'excluded');

            return;
        }
        $query->where('snipe_it_assets.state', '!=', 'excluded')
            ->where(fn ($q) => $q->where('snipe_it_assets.present', true)->orWhereNotNull('snipe_it_assets.device_id')->orWhereNotNull('snipe_it_assets.ip_address_id'));
        match ($view) {
            'linked' => $query->whereNotNull('snipe_it_assets.device_id'),
            // A manually removed documentation stays an IPAM asset: it is not a new asset to confirm.
            'ipam' => $query->whereNull('snipe_it_assets.device_id')
                ->where(fn ($q) => $q->whereNotNull('snipe_it_assets.ip_address_id')->orWhereNotNull('snipe_it_assets.removed_address')),
            default => $query->whereNull('snipe_it_assets.ip_address_id')->whereNull('snipe_it_assets.removed_address')->whereNull('snipe_it_assets.device_id'),
        };
    }

    #[Scope]
    protected function withProblem(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNotNull('snipe_it_assets.problem')->orWhere('snipe_it_assets.present', false)
            ->orWhere(fn ($q) => $q->whereNotNull('snipe_it_assets.device_id')->whereHas('externalModel', fn ($q) => $q->whereNotNull('problem'))));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'present' => 'boolean', 'needs_confirmation' => 'boolean', 'removed_address' => 'integer'];
    }

    public function sync(): BelongsTo
    {
        return $this->belongsTo(SnipeItSync::class);
    }

    public function externalModel(): BelongsTo
    {
        return $this->belongsTo(SnipeItModel::class, 'snipe_it_model_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function ipAddress(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class);
    }

    public function ipamData(): array
    {
        $connection = $this->sync->getRelationValue('connection');

        return [
            'name' => $this->snapshot['name'] ?? $this->snapshot['asset_tag'],
            'mac' => $this->snapshot['network']['mac'] ?? null,
            'notes' => $this->snapshot['notes'] ?? null,
            'ip' => $this->snapshot['network']['ip'] ?? null,
            'url' => $connection->server_url.'/hardware/'.$this->external_id,
            'problem' => $this->problem,
            'present' => $this->present,
            'active' => $this->sync->isActive() && ! $this->sync->error && $this->state !== 'excluded',
            'synced_at' => $this->sync->synced_at?->toISOString(),
            'asset_id' => $this->id,
            'removed' => $this->removed_address !== null,
        ];
    }

    /** Assets whose documentation was manually removed at these subnet addresses, keyed by address. */
    public static function removedIn(Subnet $subnet, array $addresses): Collection
    {
        if ($addresses === []) {
            return new Collection;
        }

        // Same site scope as the sync: client assets cover the company, linked devices only their own site.
        return static::whereIn('removed_address', $addresses)->whereNull('ip_address_id')->where('present', true)->where('state', '!=', 'excluded')
            ->whereHas('sync', fn ($q) => $q->where('company_id', Site::whereKey($subnet->site_id)->value('company_id')))
            ->where(fn ($q) => $q->whereNull('device_id')->orWhereHas('device', fn ($q) => $q->where('site_id', $subnet->site_id)))
            ->with('sync.connection')->get()->keyBy('removed_address');
    }
}

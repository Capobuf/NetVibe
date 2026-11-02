<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sync_id', 'external_id', 'snipe_it_model_id', 'device_id', 'ip_address_id', 'snapshot', 'state', 'present', 'needs_confirmation', 'problem'])]
class SnipeItAsset extends Model
{
    public const VIEWS = ['devices', 'review', 'linked', 'ipam', 'no-ip', 'sites', 'excluded'];

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
            'review' => $query->where(fn ($q) => $q->whereNotNull('snipe_it_assets.problem')->orWhere('snipe_it_assets.present', false)
                ->orWhere(fn ($q) => $q->whereNotNull('snipe_it_assets.device_id')->whereHas('externalModel', fn ($q) => $q->whereNotNull('problem')))),
            'linked' => $query->whereNotNull('snipe_it_assets.device_id'),
            'ipam' => $query->whereNotNull('snipe_it_assets.ip_address_id')->whereNull('snipe_it_assets.device_id'),
            'no-ip' => $query->where('snipe_it_assets.present', true)->whereNull('snipe_it_assets.snapshot->network->ip'),
            'sites' => $query->where('snipe_it_assets.state', 'site')->where('snipe_it_assets.present', true),
            default => null,
        };
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'present' => 'boolean', 'needs_confirmation' => 'boolean'];
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
        ];
    }
}

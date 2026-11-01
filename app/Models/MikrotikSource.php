<?php

namespace App\Models;

use App\Support\MikrotikSnapshot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['generation', 'kind', 'source_key', 'state', 'data', 'anchor', 'seen_at', 'problem', 'address', 'vlan_id', 'subnet_id', 'ip_address_id', 'linked', 'confirmed', 'edit_mode', 'expected', 'matched_at'])]
class MikrotikSource extends Model
{
    protected function casts(): array
    {
        return ['interface_from_model' => 'boolean', 'excluded_at' => 'datetime', 'data' => 'array', 'expected' => 'array', 'matched_at' => 'datetime', 'anchor' => 'array', 'seen_at' => 'datetime', 'address' => 'integer', 'linked' => 'boolean', 'confirmed' => 'boolean'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public static function blocking(int $siteId): Builder
    {
        return static::query()->where('site_id', $siteId)->where('state', '!=', 'missing')->whereNotNull('address');
    }

    public function display(): array
    {
        $connection = $this->device->mikrotikConnection;

        return [
            ...$this->only('id', 'kind', 'state', 'data', 'seen_at', 'problem', 'vlan_id', 'subnet_id', 'ip_address_id', 'edit_mode', 'matched_at'),
            'problem' => $this->displayProblem(),
            'can_associate' => $this->state === 'current' && ($this->problem === 'VLAN riportata da un’altra sorgente: conferma che il contesto L2 sia lo stesso.'
                || ($this->problem === 'VLAN documentata diversa dalla fonte. Verifica l’associazione della subnet.' && $this->data['tag'] !== null)),
            'source' => $this->device->name,
            'ip' => $this->address === null ? null : long2ip($this->address),
            'stale' => $this->state === 'unknown' || ! $connection?->activated_at
                || $connection->source_generation !== $this->generation || $connection->sync_status !== 'ok',
        ];
    }

    private function displayProblem(): ?string
    {
        if ($this->problem !== 'Indirizzo dinamico, disabilitato, invalido o con flag incompleti: conservato come dato esterno.') {
            return $this->problem;
        }

        $reasons = [];
        foreach (['dynamic' => 'dinamico', 'disabled' => 'disabilitato', 'invalid' => 'non valido'] as $flag => $label) {
            $value = MikrotikSnapshot::flag($this->data[$flag] ?? null);
            if ($value === true) {
                $reasons[] = 'l’indirizzo sul router è '.$label;
            } elseif ($value === null) {
                $reasons[] = 'RouterOS non ha indicato se l’indirizzo è '.$label;
            }
        }

        return 'Importazione automatica non eseguita: '.($reasons ? implode('; ', $reasons) : 'il motivo non è disponibile nella lettura salvata').'.';
    }

    public function interfaceDetails(): array
    {
        $connection = $this->device->mikrotikConnection;

        return [
            ...$this->only('id', 'device_interface_id', 'seen_at', 'problem'),
            'name' => $this->data['name'], 'default_name' => $this->data['default_name'],
            'mac_address' => $this->data['mac_address'],
            'stale' => $this->state !== 'current' || ! $connection?->activated_at
                || $connection->source_generation !== $this->generation || $connection->sync_status !== 'ok',
        ];
    }
}

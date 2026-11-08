<?php

namespace App\Models;

use App\Support\IpamEvidence;
use App\Support\MikrotikPortMap;
use App\Support\MikrotikSnapshot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['generation', 'kind', 'source_key', 'state', 'data', 'anchor', 'seen_at', 'present_at', 'problem', 'address', 'vlan_id', 'subnet_id', 'ip_address_id', 'linked', 'confirmed', 'edit_mode', 'expected', 'matched_at'])]
class MikrotikSource extends Model
{
    protected function casts(): array
    {
        return ['interface_from_model' => 'boolean', 'excluded_at' => 'datetime', 'data' => 'array', 'expected' => 'array', 'matched_at' => 'datetime', 'anchor' => 'array', 'seen_at' => 'datetime', 'present_at' => 'datetime', 'address' => 'integer', 'linked' => 'boolean', 'confirmed' => 'boolean'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Configured addresses occupy while present; temporary observations until the retention period
     * after their last presence. Rows of an unreadable source keep occupying until a valid read.
     */
    public static function blocking(int $siteId): Builder
    {
        return static::query()->where('site_id', $siteId)->whereNotNull('address')->withoutUnresolvedArp()
            ->where(fn ($query) => $query->where('present_at', '>=', IpamEvidence::cutoff($siteId))
                ->orWhere(fn ($query) => $query->where('state', '!=', 'missing')
                    // A disabled reservation or address is configuration that assigns nothing.
                    ->where(fn ($query) => $query->whereIn('kind', ['address', 'lease'])
                        ->where(fn ($query) => $query->whereNull('data->disabled')->orWhereNotIn('data->disabled', ['true', 'yes']))
                        ->orWhereNotExists(fn ($connection) => $connection->selectRaw('1')->from('mikrotik_connections')
                            ->whereColumn('mikrotik_connections.device_id', 'mikrotik_sources.device_id')
                            ->whereColumn('mikrotik_connections.source_generation', 'mikrotik_sources.generation')
                            ->whereNotNull('mikrotik_connections.activated_at')->where('mikrotik_connections.sync_status', 'ok')))));
    }

    #[Scope]
    protected function withoutUnresolvedArp(Builder $query): Builder
    {
        // An unresolved ARP request without a MAC is not evidence of an occupied IP.
        return $query->where(fn ($query) => $query->where('kind', '!=', 'observation')
            ->orWhereNull('data->menu')->orWhere('data->menu', '!=', '/ip/arp')
            ->orWhereNull('data->status')->orWhereNotIn('data->status', ['failed', 'incomplete'])
            ->orWhere(fn ($mac) => $mac->whereNotNull('data->mac')->where('data->mac', '!=', '')));
    }

    public function display(): array
    {
        $connection = $this->device->mikrotikConnection;
        $stale = $this->state === 'unknown' || ! $connection?->activated_at
            || $connection->source_generation !== $this->generation || $connection->sync_status !== 'ok';

        return [
            ...$this->only('id', 'kind', 'state', 'data', 'seen_at', 'present_at', 'problem', 'vlan_id', 'subnet_id', 'ip_address_id', 'edit_mode', 'matched_at'),
            'problem' => $this->displayProblem(),
            'import_note' => $this->importNote(),
            'can_associate' => $this->state === 'current' && ($this->problem === 'VLAN riportata da un’altra sorgente: conferma che il contesto L2 sia lo stesso.'
                || ($this->problem === 'VLAN documentata diversa dalla fonte. Verifica l’associazione della subnet.' && $this->data['tag'] !== null)),
            'source' => $this->device->name,
            'device_id' => $this->device_id,
            'site_id' => $this->site_id,
            'ip' => $this->address === null ? null : long2ip($this->address),
            'stale' => $stale,
            // Only a reachable ARP entry proves current presence; other states stay neutral.
            'presence' => ! $stale && $this->state === 'current' && ($this->data['menu'] ?? null) === '/ip/arp'
                && ($this->data['status'] ?? null) === 'reachable' ? 'online' : null,
            'read_at' => $connection?->synced_at,
        ];
    }

    public function importNote(): ?string
    {
        $notes = [
            'Indirizzo dinamico: non importato nell’IPAM.',
            'Indirizzo disabilitato: non importato nell’IPAM.',
            'Lease disabilitata: non importata nell’IPAM.',
            'Server DHCP disabilitato: lease non importata nell’IPAM.',
            'VLAN disabilitata: non importata.',
            'Appartenenza VLAN disabilitata: non importata.',
        ];
        if (in_array($this->problem, $notes, true)) {
            return $this->problem;
        }
        if ($this->problem === 'Indirizzo dinamico, disabilitato, invalido o con flag incompleti: conservato come dato esterno.') {
            if (MikrotikSnapshot::flag($this->data['disabled'] ?? null) === true) {
                return 'Indirizzo disabilitato: non importato nell’IPAM.';
            }
            if (MikrotikSnapshot::flag($this->data['dynamic'] ?? null) === true) {
                return 'Indirizzo dinamico: non importato nell’IPAM.';
            }
        }

        return null;
    }

    public function displayProblem(): ?string
    {
        if ($this->importNote() !== null) {
            return null;
        }
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
            'port_type' => MikrotikPortMap::type($this->data['default_name'] ?? ''),
            'stale' => $this->state !== 'current' || ! $connection?->activated_at
                || $connection->source_generation !== $this->generation || $connection->sync_status !== 'ok',
        ];
    }
}

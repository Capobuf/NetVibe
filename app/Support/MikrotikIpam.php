<?php

namespace App\Support;

use App\Models\IpAddress;
use App\Models\MikrotikSource;
use App\Models\Subnet;
use App\Models\Vlan;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MikrotikIpam
{
    public static function forSubnet(Subnet $subnet): Collection
    {
        return (new NetworkExclusions($subnet->site_id))->display(MikrotikSource::where('site_id', $subnet->site_id)
            ->withoutUnresolvedArp()
            ->where(fn ($query) => $query->where('subnet_id', $subnet->id)
                ->orWhereBetween('address', [$subnet->network_address, $subnet->broadcast_address]))
            ->with('device.mikrotikConnection')->orderByDesc('seen_at')->get());
    }

    public static function networkChange(Subnet|Vlan $record, array $next, ?string $mode): void
    {
        if (! $record->exists) {
            return;
        }
        $fields = $record instanceof Subnet ? ['cidr', 'vlan_id'] : ['tag'];
        $changes = array_filter(array_intersect_key($next, array_flip($fields)), fn ($value, $field) => $value != $record->$field, ARRAY_FILTER_USE_BOTH);
        if (! $changes) {
            return;
        }
        $sources = MikrotikSource::where('site_id', $record->site_id)
            ->where($record instanceof Subnet ? 'subnet_id' : 'vlan_id', $record->id)
            ->where('kind', $record instanceof Subnet ? 'subnet' : 'vlan')->where('state', '!=', 'missing')->lockForUpdate()->get();
        if ($sources->isEmpty()) {
            return;
        }
        if (! in_array($mode, ['planned', 'manual'], true)) {
            throw ValidationException::withMessages(['mikrotik_edit_mode' => 'Il dato è alimentato anche da MikroTik. Scegli Modifica prevista oppure Mantieni valore manuale.']);
        }
        if (array_key_exists('vlan_id', $changes)) {
            $changes['tag'] = $changes['vlan_id'] === null ? null : Vlan::where('site_id', $record->site_id)->findOrFail($changes['vlan_id'])->tag;
            unset($changes['vlan_id']);
        }
        foreach ($sources as $source) {
            $source->fill(['edit_mode' => $mode, 'expected' => $mode === 'planned' ? $changes : null, 'matched_at' => null])->save();
        }
    }

    public static function manualChange(IpAddress $ip, array $next, ?string $mode): void
    {
        if (! $ip->exists) {
            return;
        }
        $changes = [];
        foreach (['address', 'mac_address', 'device_id'] as $field) {
            if (! array_key_exists($field, $next)) {
                continue;
            }
            $value = $field === 'mac_address' ? MacAddress::normalize($next[$field]) : $next[$field];
            if ($ip->getOriginal($field) != $value) {
                $changes[$field] = $value;
            }
        }
        if (! $changes) {
            return;
        }
        $sources = MikrotikSource::where('site_id', $ip->subnet->site_id)->where('state', '!=', 'missing')
            ->where(fn ($query) => $query->where('ip_address_id', $ip->id)->orWhere('address', $ip->getOriginal('address')))->get();
        $changes = array_filter($changes, fn ($value, $field) => $sources->contains(fn ($source) => match ($field) {
            'mac_address' => ! empty($source->data['mac']),
            'device_id' => $source->kind === 'address',
            default => $source->ip_address_id === $ip->id,
        }), ARRAY_FILTER_USE_BOTH);
        if (! $changes) {
            return;
        }
        if (! in_array($mode, ['planned', 'manual'], true)) {
            throw ValidationException::withMessages(['mikrotik_edit_mode' => 'Questo valore è alimentato anche da MikroTik. Scegli Modifica prevista oppure Mantieni valore manuale.']);
        }
        $ip->mikrotik_edit_mode = $mode;
        $ip->mikrotik_expected = $mode === 'planned' ? ['values' => $changes, 'source_ids' => $sources->modelKeys()] : null;
        $ip->mikrotik_matched_at = null;
    }

    public static function recognizeExpected(int $siteId, int $deviceId): void
    {
        $ips = IpAddress::whereIn('subnet_id', Subnet::where('site_id', $siteId)->visible()->select('id'))
            ->where('mikrotik_edit_mode', 'planned')->whereNull('mikrotik_matched_at')->lockForUpdate()->get();
        foreach ($ips as $ip) {
            $expected = $ip->mikrotik_expected;
            $sources = MikrotikSource::where('site_id', $siteId)->whereIn('id', $expected['source_ids'])
                ->where('device_id', $deviceId)->where('state', 'current')->whereNull('problem')->with('device.mikrotikConnection')->get()
                ->filter(fn ($source) => $source->device->mikrotikConnection?->source_generation === $source->generation);
            $matched = $sources->contains(function ($source) use ($expected) {
                foreach ($expected['values'] as $field => $value) {
                    $actual = match ($field) {
                        'address' => $source->address,
                        'mac_address' => $source->data['mac'] ?? null,
                        'device_id' => $source->kind === 'address' ? $source->device_id : null,
                    };
                    if ($field === 'mac_address') {
                        // Expectations saved before MAC normalization may hold another format.
                        $value = MacAddress::normalize($value);
                    }
                    if ($actual != $value) {
                        return false;
                    }
                }

                return true;
            });
            if ($matched) {
                $ip->mikrotik_matched_at = now();
                $ip->save();
            }
        }
    }
}

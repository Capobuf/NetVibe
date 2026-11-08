<?php

namespace App\Support;

use App\Models\Company;
use App\Models\IpAddress;
use App\Models\MikrotikSource;
use App\Models\Subnet;
use App\Models\UnifiDevice;
use App\Models\UnifiObservation;
use Carbon\CarbonInterface;

/** Shared rules for IP records documented from integration evidence. */
class IpamEvidence
{
    public static function cutoff(int $siteId): CarbonInterface
    {
        $days = Company::whereHas('sites', fn ($query) => $query->whereKey($siteId))->value('observation_retention_days');

        return now()->subDays($days ?? 30);
    }

    /** The visible site subnet that holds the address as a usable host; subnets never overlap within a site. */
    public static function subnetFor(int $siteId, int $address): ?Subnet
    {
        $subnet = Subnet::where('site_id', $siteId)->visible()->where('network_address', '<=', $address)
            ->where('broadcast_address', '>=', $address)->lockForUpdate()->first();
        if (! $subnet) {
            return null;
        }
        [$first, $last] = Ipv4Ranges::usableBounds($subnet->network_address, $subnet->broadcast_address);

        return $address >= $first && $address <= $last ? $subnet : null;
    }

    /** Returns the single record of the address, creating an automatic one without inventing manual fields. */
    public static function document(Subnet $subnet, int $address, ?int $deviceId = null): array
    {
        $ip = $subnet->ipAddresses()->where('address', $address)->lockForUpdate()->first();
        if ($ip) {
            return [$ip, false];
        }
        $ip = $subnet->ipAddresses()->make(['address' => $address, 'device_id' => $deviceId]);
        $ip->auto_managed = true;
        $ip->save();

        return [$ip, true];
    }

    /**
     * Fills automatic records with the values their current sources agree on. Disagreeing sources leave
     * the field empty and stay visible side by side; once the technician saves the record it is never rewritten.
     */
    public static function populate(int $siteId): void
    {
        $ips = IpAddress::whereIn('subnet_id', Subnet::where('site_id', $siteId)->select('id'))
            ->where('auto_managed', true)->lockForUpdate()->get();
        if ($ips->isEmpty()) {
            return;
        }
        $addresses = $ips->pluck('address')->all();
        // Sources with an open problem (for example a second router claiming the address) are not facts to copy.
        $mikrotik = MikrotikSource::where('site_id', $siteId)->where('state', 'current')->whereNull('excluded_at')->whereNull('problem')
            ->whereIn('address', $addresses)->withoutUnresolvedArp()->get()->groupBy('address');
        $unifi = UnifiObservation::forSite($siteId)->where('state', 'current')->where('kind', '!=', 'network')->whereNull('problem')
            ->whereIn('address', $addresses)->get()->groupBy('address');
        // Only a stable UniFi device reference already mapped to a device of this site assigns it.
        $devices = UnifiDevice::whereNotNull('device_id')->where('present', true)
            ->whereHas('device', fn ($query) => $query->where('site_id', $siteId))->get()
            ->groupBy(fn ($device) => $device->unifi_site_id.':'.$device->external_id);
        foreach ($ips as $ip) {
            $facts = ['mac_address' => [], 'hostname' => [], 'description' => [], 'device_id' => []];
            foreach ($mikrotik->get($ip->address, []) as $source) {
                $facts['mac_address'][] = $source->data['mac'] ?? null;
                $facts['hostname'][] = $source->data['hostname_observed'] ?? $source->data['identity'] ?? null;
                $facts['description'][] = $source->data['comment'] ?? null;
                $facts['device_id'][] = $source->kind === 'address' ? $source->device_id : null;
            }
            foreach ($unifi->get($ip->address, []) as $observation) {
                $facts['mac_address'][] = $observation->data['mac'] ?? null;
                $facts['hostname'][] = $observation->data['hostname_observed'] ?? null;
                $facts['description'][] = $observation->data['alias'] ?? null;
                $mapped = $observation->kind === 'device' ? $devices->get($observation->unifi_site_id.':'.$observation->source_key) : null;
                $facts['device_id'][] = $mapped?->count() === 1 ? $mapped->first()->device_id : null;
            }
            if (! $mikrotik->has($ip->address) && ! $unifi->has($ip->address)) {
                // Without current evidence the last documented values remain until the record expires.
                continue;
            }
            foreach ($facts as $field => $values) {
                // UniFi names an unnamed client after its MAC: that is not a hostname.
                $values = collect($values)->filter(fn ($value) => $value !== null && $value !== ''
                    && ($field !== 'hostname' || MikrotikSnapshot::mac(strtoupper(trim($value))) === null))
                    ->unique(fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value)->values();
                $value = $values->count() === 1 ? $values->first() : null;
                $ip->$field = is_string($value) && mb_strlen($value) > ($field === 'hostname' ? 255 : 2000) ? null : $value;
            }
            if ($ip->isDirty()) {
                $ip->save();
            }
        }
    }

    /** Online when any readable source proves presence, offline when one proves absence, otherwise unknown. */
    public static function presence(int $siteId, array $addresses): array
    {
        $states = [];
        $sources = MikrotikSource::where('site_id', $siteId)->whereIn('address', $addresses)->whereNull('excluded_at')
            ->with('device.mikrotikConnection')->get()->map(fn ($source) => [$source->address, $source->display()['presence']])
            ->concat(UnifiObservation::forSite($siteId)->whereIn('address', $addresses)->with('unifiSite.unifiConnection', 'vlan', 'subnet.vlan')->get()
                ->map(fn ($observation) => [$observation->address, $observation->display()['presence']]));
        foreach ($sources as [$address, $presence]) {
            $current = $states[$address] ?? 'unknown';
            $states[$address] = $current === 'online' || $presence === 'online' ? 'online'
                : ($current === 'offline' || $presence === 'offline' ? 'offline' : 'unknown');
        }

        return $states;
    }

    /** Truthful feedback after an explicit deletion: integrations are never modified by NetVibe. */
    public static function releaseMessage(Subnet $subnet, int $address): string
    {
        if (MikrotikSource::blocking($subnet->site_id)->where('address', $address)->exists()
            || UnifiObservation::blocking($subnet->site_id)->where('address', $address)->exists()) {
            return 'Documentazione rimossa. L’indirizzo è ancora rilevato dalle integrazioni e può essere documentato di nuovo alla prossima sincronizzazione.';
        }

        return $subnet->isExcluded($address) ? 'Documentazione rimossa. L’indirizzo resta escluso dai suggerimenti.'
            : 'IP liberato e documentazione rimossa.';
    }

    /**
     * Frees automatic records whose evidence has expired. Callers run it only after a complete
     * successful read; evidence of unreachable sources still occupies the address.
     */
    public static function expire(int $siteId): void
    {
        $ips = IpAddress::whereIn('subnet_id', Subnet::where('site_id', $siteId)->select('id'))
            ->where('auto_managed', true)
            ->whereNull('ip_status_id')->whereNull('device_interface_id')->whereNull('mikrotik_edit_mode')
            ->whereDoesntHave('tags')->whereDoesntHave('snipeItAsset')
            ->whereNotIn('address', UnifiIpam::addressSources($siteId, true)->select('address'))
            ->lockForUpdate()->get();
        foreach ($ips as $ip) {
            // A returning UniFi network must not report the expired record as a changed association.
            foreach (UnifiObservation::where('ip_address_id', $ip->id)->whereNotNull('anchor')->get() as $observation) {
                $observation->anchor = array_diff_key($observation->anchor, ['ip_address_id' => true]);
                $observation->save();
            }
            $ip->delete();
        }
    }
}

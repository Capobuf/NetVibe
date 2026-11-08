<?php

namespace App\Support;

use App\Models\Company;
use App\Models\IpAddress;
use App\Models\MikrotikSource;
use App\Models\Subnet;
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
            ->whereNull('description')->whereNull('hostname')->whereNull('mac_address')
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

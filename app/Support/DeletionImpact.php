<?php

namespace App\Support;

use App\Models\Cable;
use App\Models\CableEnd;
use App\Models\Company;
use App\Models\Device;
use App\Models\IpStatus;
use App\Models\Rack;
use App\Models\RackItem;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeletionImpact
{
    // Preview and confirmation read the same locked records inside a transaction.
    public static function networks(Company $company, ?Site $site = null, ?Subnet $subnet = null, ?Vlan $vlan = null): array
    {
        $company = Company::query()->lockForUpdate()->findOrFail($company->id);
        $sites = $company->sites()->orderBy('id')->lockForUpdate()->get();
        $siteIds = $site ? [$sites->findOrFail($site->id)->id] : $sites->modelKeys();
        $vlans = Vlan::query()->whereIn('site_id', $siteIds)
            ->when($vlan, fn ($query) => $query->whereKey($vlan->id))
            ->when($subnet, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('id')->lockForUpdate()->get();
        $subnets = Subnet::query()->whereIn('site_id', $siteIds)
            ->when($subnet, fn ($query) => $query->whereKey($subnet->id))
            ->when($vlan, fn ($query) => $query->where('vlan_id', $vlan->id))
            ->orderBy('id')->lockForUpdate()->get();

        abort_if(($subnet && $subnets->isEmpty()) || ($vlan && $vlans->isEmpty()), 404);

        $ips = DB::table('ip_addresses')->whereIn('subnet_id', $subnets->modelKeys())->orderBy('id')->lockForUpdate()->get();
        $ranges = DB::table('excluded_ranges')->whereIn('subnet_id', $subnets->modelKeys())->orderBy('id')->lockForUpdate()->get();
        $summaries = DB::table('subnet_summary_entries')->whereIn('subnet_id', $subnets->modelKeys())->orderBy('id')->lockForUpdate()->get();
        $links = DB::table('ip_address_tag')->whereIn('ip_address_id', $ips->pluck('id'))
            ->orderBy('ip_address_id')->orderBy('tag_id')->lockForUpdate()->get();
        $tags = $company->tags()->when($site, fn ($query) => $query->whereIn('id', $links->pluck('tag_id')))
            ->orderBy('id')->lockForUpdate()->get();

        $devices = DB::table('devices')->whereIn('site_id', $siteIds)
            ->when($subnet || $vlan, fn ($q) => $q->whereRaw('1 = 0'))->orderBy('id')->lockForUpdate()->get();
        $interfaces = DB::table('device_interfaces')->whereIn('device_id', $devices->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $racks = Rack::query()->whereIn('site_id', $siteIds)
            ->when($subnet || $vlan, fn ($q) => $q->whereRaw('1 = 0'))->orderBy('id')->lockForUpdate()->get();
        $rackItems = RackItem::query()->whereIn('rack_id', $racks->modelKeys())->orderBy('id')->lockForUpdate()->get();

        return [
            'cables' => self::cables($devices->pluck('id')->all()),
            'racks' => $racks->toArray(), 'rack_items' => $rackItems->toArray(),
            'devices' => $devices->all(), 'interfaces' => $interfaces->all(),
            'device_links' => $ips->filter(fn ($ip) => $ip->device_id !== null)->values()->all(),
            'scope' => ['company' => $company->id, 'site' => $site?->id, 'subnet' => $subnet?->id, 'vlan' => $vlan?->id],
            'company' => $company->only('id', 'name'),
            'sites' => $sites->map->only('id', 'name')->all(),
            'vlans' => $vlans->toArray(),
            'subnets' => $subnets->toArray(),
            'ips' => $ips->map(fn ($ip) => [...(array) $ip, 'ip' => long2ip($ip->address)])->all(),
            'ranges' => $ranges->map(fn ($range) => [...(array) $range,
                'start_ip' => long2ip($range->start_address), 'end_ip' => long2ip($range->end_address),
            ])->all(),
            'summaries' => $summaries->map(fn ($entry) => [...(array) $entry, 'values' => json_decode($entry->values, true)])->all(),
            'tags' => $tags->toArray(),
            'tag_links' => $links->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function device(Company $company, Site $site, Device $device): array
    {
        Company::query()->lockForUpdate()->findOrFail($company->id);
        $device = $site->devices()->lockForUpdate()->findOrFail($device->id);

        return [
            'cables' => self::cables([$device->id]),
            'device' => $device->toArray(),
            'rack_item' => $device->rackItem()->with('rack:id,name,revision')->first()?->toArray(),
            'interfaces' => $device->interfaces()->orderBy('id')->lockForUpdate()->get()->toArray(),
            'ips' => $device->ipAddresses()->orderBy('id')->lockForUpdate()->get()
                ->map(fn ($ip) => [...$ip->toArray(), 'ip' => long2ip($ip->address)])->all(),
        ];
    }

    /** @param list<int> $ids */
    public static function deleteDevices(array $ids): void
    {
        $supportsOtherDevices = DB::table('rack_items as supported')
            ->join('rack_items as support', fn ($join) => $join->on('support.rack_id', '=', 'supported.rack_id')->on('support.key', '=', 'supported.shelf_key'))
            ->whereIn('support.device_id', $ids)->whereNotIn('supported.device_id', $ids)->exists();
        if ($supportsOtherDevices) {
            throw ValidationException::withMessages(['confirmed' => 'Il dispositivo sostiene altri apparati nel rack. Spostali o rimuovili dal rack prima di eliminarlo.']);
        }
        Rack::query()->whereIn('id', RackItem::query()->whereIn('device_id', $ids)->select('rack_id'))->increment('revision');
        $cables = Cable::query()->whereIn('id', CableEnd::query()->whereIn('device_id', $ids)->select('cable_id'))->get();
        Site::query()->whereKey($cables->pluck('site_id')->unique())->increment('cabling_revision');
        Cable::query()->whereKey($cables->modelKeys())->delete();
        DB::table('ip_addresses')->whereIn('device_id', $ids)->delete();
        DB::table('devices')->whereIn('id', $ids)->delete();
    }

    private static function cables(array $deviceIds): array
    {
        return Cable::query()->whereIn('id', CableEnd::query()->whereIn('device_id', $deviceIds)->select('cable_id'))
            ->with('ends')->orderBy('id')->lockForUpdate()->get()->map->workspaceData()->all();
    }

    public static function status(IpStatus $status): array
    {
        $status = IpStatus::query()->lockForUpdate()->findOrFail($status->id);
        $ips = DB::table('ip_addresses')->where('ip_status_id', $status->id)->orderBy('id')->lockForUpdate()->get();
        $contexts = DB::table('subnets')->join('sites', 'sites.id', '=', 'subnets.site_id')
            ->join('companies', 'companies.id', '=', 'sites.company_id')
            ->whereIn('subnets.id', $ips->pluck('subnet_id'))->orderBy('subnets.id')
            ->get(['subnets.id', 'subnets.cidr', 'sites.name as site_name', 'companies.id as company_id', 'companies.name as company_name'])
            ->keyBy('id');
        $links = DB::table('ip_address_tag')->whereIn('ip_address_id', $ips->pluck('id'))
            ->orderBy('ip_address_id')->orderBy('tag_id')->lockForUpdate()->get();

        return [
            'status' => $status->only('id', 'name'),
            'ips' => $ips->map(fn ($ip) => [...(array) $ip,
                'ip' => long2ip($ip->address), 'context' => $contexts[$ip->subnet_id],
            ])->all(),
            'tag_links' => $links->all(),
        ];
    }

    public static function token(array $impact): string
    {
        // Authenticate reviewed data, including identities when counts stay unchanged.
        return hash_hmac('sha256', json_encode($impact, JSON_THROW_ON_ERROR), config('app.key'));
    }

    public static function preview(array $impact): array
    {
        return [...$impact, 'impact_token' => self::token($impact)];
    }

    public static function deleteSubnets(array $ids): void
    {
        DB::table('ip_addresses')->whereIn('subnet_id', $ids)->delete();
        DB::table('excluded_ranges')->whereIn('subnet_id', $ids)->delete();
        DB::table('subnet_summary_entries')->whereIn('subnet_id', $ids)->delete();
        Subnet::query()->whereKey($ids)->delete();
    }
}

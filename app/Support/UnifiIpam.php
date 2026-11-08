<?php

namespace App\Support;

use App\Exceptions\UnifiException;
use App\Models\IpAddress;
use App\Models\MikrotikSource;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\UnifiDevice;
use App\Models\UnifiObservation;
use App\Models\UnifiSite;
use App\Models\Vlan;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UnifiIpam
{
    public static function addressSources(int $siteId, bool $blocking = false): Builder
    {
        $unifi = $blocking ? UnifiObservation::blocking($siteId) : UnifiObservation::forSite($siteId)->whereNotNull('address');

        return DB::query()->fromSub(MikrotikSource::blocking($siteId)->select('address', 'data')->toBase()
            ->unionAll($unifi->select('address', 'data')->toBase()), 'observations');
    }

    public static function resetForMapping(UnifiSite $site): void
    {
        UnifiObservation::where('unifi_site_id', $site->id)->delete();
    }

    public static function forSubnet(Subnet $subnet): Collection
    {
        return UnifiObservation::forSite($subnet->site_id)
            ->where(fn ($query) => $query->where('subnet_id', $subnet->id)
                ->orWhereBetween('address', [$subnet->network_address, $subnet->broadcast_address]))
            ->with(['unifiSite.unifiConnection', 'vlan', 'subnet.vlan'])->orderByDesc('seen_at')->get()->map->display();
    }

    public static function networks(int $siteId): Collection
    {
        return UnifiObservation::forSite($siteId)->where('kind', 'network')
            ->with(['unifiSite.unifiConnection', 'vlan', 'subnet.vlan'])->orderBy('id')->get()->map->display();
    }

    // The sync owns the transaction and locks the mapped site before publishing a complete snapshot.
    public function capture(UnifiSite $source, ?array $networks, array $clients, array $devices = [], ?array $reservations = null): void
    {
        $site = Site::query()->lockForUpdate()->findOrFail($source->site_id);
        $cutoff = IpamEvidence::cutoff($site->id);
        $seen = [];
        foreach ($networks ?? [] as $network) {
            foreach ($this->networkRows($network) as $key => $data) {
                $observation = $this->record($source, 'network', $key, $data);
                $observation->address = $data['host_ip'] && $data['enabled'] !== false ? ip2long($data['host_ip']) : null;
                if ($observation->address !== null) {
                    $observation->present_at = now();
                }
                if ($source->inventory_activated_at) {
                    $this->associateNetwork($site, $observation);
                }
                $observation->save();
                $seen[] = $observation->id;
            }
        }
        foreach (['client' => $clients, 'device' => $devices, 'reservation' => $reservations ?? []] as $kind => $items) {
            foreach ($items as $item) {
                if ($kind === 'client' && ! in_array($item['type'] ?? null, ['WIRED', 'WIRELESS'], true)) {
                    continue;
                }
                $ip = match ($kind) {
                    'device' => $item['ip_address'] ?? null, 'reservation' => $item['ip'], default => $item['ipAddress'] ?? null,
                };
                if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || empty($item['id'])) {
                    continue;
                }
                $data = $kind === 'reservation'
                    ? ['name' => $item['name'], 'hostname_observed' => $item['hostname'] ?? $item['name'], 'mac' => $item['mac'],
                        'type' => 'RESERVATION', 'ip' => $ip, 'dns' => $item['dns'],
                        // An alias given in UniFi documents the client better than its reported hostname.
                        'alias' => $item['hostname'] !== null && $item['name'] !== $item['hostname'] ? $item['name'] : null]
                    : ['name' => $item['name'] ?? null, 'hostname_observed' => $item['name'] ?? null,
                        'mac' => MikrotikSnapshot::mac($kind === 'device' ? ($item['mac_address'] ?? null) : ($item['macAddress'] ?? null)),
                        'type' => $item['type'] ?? 'DEVICE', 'ip' => $ip,
                        ...($kind === 'device' ? ['state' => $item['state'] ?? null] : [])];
                $observation = $this->record($source, $kind, $item['id'], $data);
                $observation->address = ip2long($ip);
                // Listed clients are connected, an adopted device is present only when online, and a
                // reservation carries the controller's own last sighting of its client.
                $present = match ($kind) {
                    'client' => now(),
                    'device' => ($data['state'] ?? null) === 'ONLINE' ? now() : null,
                    default => $item['last_seen'] ? Carbon::createFromTimestamp(min($item['last_seen'], now()->timestamp)) : null,
                };
                if ($present && (! $observation->present_at || $present->gt($observation->present_at))) {
                    $observation->present_at = $present;
                }
                // A changed observed address must never move an existing documented assignment.
                $subnet = IpamEvidence::subnetFor($site->id, $observation->address);
                $ip = $subnet?->ipAddresses()->where('address', $observation->address)->first();
                if ($subnet && ! $ip && $source->inventory_activated_at && $data['mac'] !== null
                    && ($kind === 'reservation' || $observation->present_at?->gte($cutoff))) {
                    [$ip] = IpamEvidence::document($subnet, $observation->address);
                }
                $observation->subnet_id = $subnet?->id;
                $observation->ip_address_id = $ip?->id;
                $observation->save();
                $seen[] = $observation->id;
            }
        }
        // Collections that could not be read keep their last rows as not updated instead of missing.
        $unread = array_keys(array_filter(['network' => $networks === null, 'reservation' => $reservations === null]));
        UnifiObservation::where('unifi_site_id', $source->id)->whereNotIn('id', $seen)
            ->whereNotIn('kind', $unread)->update(['state' => 'missing']);
        UnifiObservation::where('unifi_site_id', $source->id)->whereIn('kind', $unread)->where('state', 'current')->update(['state' => 'unknown']);
        self::recognizeExpected($source);
        if ($source->inventory_activated_at) {
            IpamEvidence::populate($site->id);
            IpamEvidence::expire($site->id);
        }
    }

    public function activate(UnifiSite $source): void
    {
        $site = Site::query()->lockForUpdate()->findOrFail($source->site_id);
        foreach (UnifiObservation::where('unifi_site_id', $source->id)->where('kind', 'network')->where('state', 'current')->lockForUpdate()->get() as $observation) {
            $this->associateNetwork($site, $observation);
            $observation->save();
        }
    }

    private function record(UnifiSite $source, string $kind, string $key, array $data): UnifiObservation
    {
        $record = UnifiObservation::firstOrNew(['unifi_site_id' => $source->id, 'kind' => $kind, 'source_key' => $key]);
        $record->fill(['site_id' => $source->site_id, 'data' => $data, 'state' => 'current', 'seen_at' => now(), 'problem' => null]);

        return $record;
    }

    private function networkRows(array $network): array
    {
        $rules = [
            'id' => ['required', 'string', 'max:80'], 'name' => ['nullable', 'string', 'max:255'],
            'management' => ['required', 'in:GATEWAY,SWITCH,UNMANAGED'],
            'vlanId' => ['nullable', 'integer', 'between:1,4094'], 'default' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'], 'deviceId' => ['nullable', 'string', 'max:80'],
            'ipv4Configuration' => ['nullable', 'array'],
            'ipv4Configuration.hostIpAddress' => ['required_with:ipv4Configuration', 'ipv4'],
            'ipv4Configuration.prefixLength' => ['required_with:ipv4Configuration', 'integer', 'between:0,32'],
            'ipv4Configuration.additionalHostIpSubnets' => ['sometimes', 'array', 'max:64'],
            'ipv4Configuration.additionalHostIpSubnets.*' => ['string'],
            'ipv4Configuration.dhcpConfiguration' => ['nullable', 'array'],
            'ipv4Configuration.dhcpConfiguration.mode' => ['required_with:ipv4Configuration.dhcpConfiguration', 'in:SERVER,RELAY'],
            'ipv4Configuration.dhcpConfiguration.ipAddressRange' => ['nullable', 'array'],
            'ipv4Configuration.dhcpConfiguration.ipAddressRange.start' => ['required_with:ipv4Configuration.dhcpConfiguration.ipAddressRange', 'ipv4'],
            'ipv4Configuration.dhcpConfiguration.ipAddressRange.stop' => ['required_with:ipv4Configuration.dhcpConfiguration.ipAddressRange', 'ipv4'],
            'ipv4Configuration.dhcpConfiguration.gatewayIpAddressOverride' => ['nullable', 'ipv4'],
            'ipv4Configuration.dhcpConfiguration.dnsServerIpAddressesOverride' => ['nullable', 'array'],
            'ipv4Configuration.dhcpConfiguration.dnsServerIpAddressesOverride.*' => ['ipv4'],
            'ipv4Configuration.dhcpConfiguration.dhcpServerIpAddresses' => ['nullable', 'array'],
            'ipv4Configuration.dhcpConfiguration.dhcpServerIpAddresses.*' => ['ipv4'],
        ];
        if (Validator::make($network, $rules)->fails()) {
            throw new UnifiException('format');
        }
        $id = $network['id'];
        $data = ['name' => $network['name'] ?? null, 'management' => $network['management'] ?? null,
            'tag' => $network['vlanId'] ?? null, 'default' => ($network['default'] ?? false) === true,
            'enabled' => $network['enabled'] ?? null, 'device_id' => $network['deviceId'] ?? null,
            'cidr' => null, 'host_ip' => null, 'dhcp' => null];
        $config = $network['ipv4Configuration'] ?? null;
        if (! in_array($data['management'], ['GATEWAY', 'SWITCH'], true) || ! is_array($config)) {
            return [$id => $data];
        }
        $dhcp = $config['dhcpConfiguration'] ?? null;
        if (is_array($dhcp)) {
            $data['dhcp'] = ['mode' => $dhcp['mode'] ?? null,
                'start' => $this->ipv4($dhcp['ipAddressRange']['start'] ?? null),
                'stop' => $this->ipv4($dhcp['ipAddressRange']['stop'] ?? null),
                'gateway' => $this->ipv4($dhcp['gatewayIpAddressOverride'] ?? null),
                'dns' => array_values(array_filter(array_map($this->ipv4(...), $dhcp['dnsServerIpAddressesOverride'] ?? []))),
                'servers' => array_values(array_filter(array_map($this->ipv4(...), $dhcp['dhcpServerIpAddresses'] ?? [])))];
        }
        $cidrs = [];
        if (isset($config['hostIpAddress'], $config['prefixLength'])) {
            $cidrs[] = $config['hostIpAddress'].'/'.$config['prefixLength'];
        }
        $cidrs = [...$cidrs, ...($config['additionalHostIpSubnets'] ?? [])];
        $rows = [];
        foreach ($cidrs as $index => $cidr) {
            if (! is_string($cidr) || ! ($range = Ipv4Cidr::parse($cidr))) {
                throw new UnifiException('format');
            }
            $rows[$id.':'.$index] = [...$data, 'cidr' => $range['cidr'], 'host_ip' => explode('/', $cidr)[0]];
        }

        return $rows ?: [$id => $data];
    }

    private function ipv4(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $value : null;
    }

    private function associateNetwork(Site $site, UnifiObservation $source): void
    {
        $data = $source->data;
        if ($data['enabled'] === false) {
            return;
        }
        $tag = $data['default'] ? null : $data['tag'];
        $vlan = is_int($tag) && $tag >= 1 && $tag <= 4094 ? $site->vlans()->where('tag', $tag)->first() : null;
        if ($vlan?->excluded_at) {
            return;
        }
        if ($source->linked && ($source->anchor['cidr'] ?? null) !== $data['cidr']) {
            $source->problem = 'La rete sorgente è cambiata: la documentazione esistente è stata conservata.';

            return;
        }
        if ($source->linked && $source->vlan_id && $source->vlan_id !== $vlan?->id) {
            $source->problem = 'La VLAN sorgente è cambiata: la documentazione esistente è stata conservata.';

            return;
        }
        if ($source->linked && $source->vlan_id === null && ($source->anchor['tag'] ?? null) !== null && ! $data['default']) {
            $source->problem = 'La VLAN documentata è stata rimossa.';

            return;
        }
        if (! $vlan && is_int($tag) && $tag >= 1 && $tag <= 4094) {
            $vlan = $site->vlans()->create(['tag' => $tag, 'name' => $data['name']]);
        }
        $source->anchor ??= ['cidr' => $data['cidr'], 'tag' => $data['tag']];
        $source->vlan_id = $vlan?->id;
        if ($vlan && $vlan->name !== $data['name'] && $data['name'] !== null) {
            $source->problem = 'Nome VLAN diverso dalla fonte.';
        }
        if (! $data['cidr']) {
            $source->linked = true;

            return;
        }
        $range = Ipv4Cidr::parse($data['cidr']);
        $overlap = $site->subnets()->where('network_address', '<=', $range['broadcast_address'])
            ->where('broadcast_address', '>=', $range['network_address'])->get();
        if ($overlap->contains(fn ($subnet) => $subnet->cidr !== $range['cidr'])) {
            $source->problem = 'Sovrapposizione con '.$overlap->pluck('cidr')->join(', ').'.';

            return;
        }
        $subnet = $overlap->first();
        if ($source->linked && (! $subnet || $source->subnet_id !== $subnet->id)) {
            $source->problem = 'La subnet documentata è stata modificata o rimossa.';

            return;
        }
        if ($subnet?->excluded_at || $subnet?->vlan?->excluded_at) {
            return;
        }
        $subnet ??= $site->subnets()->create([...$range, 'vlan_id' => $vlan?->id]);
        $source->subnet_id = $subnet->id;
        $source->linked = true;
        if ($subnet->vlan_id !== $vlan?->id) {
            $source->problem = 'VLAN documentata diversa dalla fonte.';
        }
        $source->address = $data['host_ip'] ? ip2long($data['host_ip']) : null;
        if ($source->address === null || $source->problem) {
            return;
        }
        [$first, $last] = Ipv4Ranges::usableBounds($subnet->network_address, $subnet->broadcast_address);
        if ($source->address < $first || $source->address > $last) {
            return;
        }
        $ip = $subnet->ipAddresses()->where('address', $source->address)->first();
        if (($source->anchor['ip_address_id'] ?? null) && ($source->anchor['ip_address_id'] ?? null) !== $ip?->id) {
            $source->problem = 'L’indirizzo documentato è cambiato: associazione originale conservata.';

            return;
        }
        if (! $ip) {
            $devices = $data['device_id'] ? UnifiDevice::where('unifi_site_id', $source->unifi_site_id)
                ->whereNotNull('device_id')->where('present', true)
                ->whereHas('device', fn ($query) => $query->where('site_id', $site->id))
                ->where('external_id', $data['device_id'])->get() : collect();
            // The source is the record's justification: no description is invented for it.
            [$ip] = IpamEvidence::document($subnet, $source->address, $devices->count() === 1 ? $devices->first()->device_id : null);
        }
        $source->ip_address_id = $ip->id;
        $source->anchor = [...$source->anchor, 'ip_address_id' => $ip->id];
    }

    public static function networkChange(Subnet|Vlan $record, array $next, ?string $mode): void
    {
        if (! $record->exists) {
            return;
        }
        $fields = $record instanceof Subnet ? ['cidr', 'vlan_id'] : ['tag', 'name'];
        $changes = array_filter(array_intersect_key($next, array_flip($fields)), fn ($value, $field) => $value != $record->$field, ARRAY_FILTER_USE_BOTH);
        if (! $changes) {
            return;
        }
        if (array_key_exists('vlan_id', $changes)) {
            $changes['tag'] = $changes['vlan_id'] === null ? null : Vlan::where('site_id', $record->site_id)->findOrFail($changes['vlan_id'])->tag;
            unset($changes['vlan_id']);
        }
        $sources = UnifiObservation::forSite($record->site_id)->where('kind', 'network')
            ->where($record instanceof Subnet ? 'subnet_id' : 'vlan_id', $record->id)->lockForUpdate()->get();
        foreach ($sources as $source) {
            self::rememberChange($source, $changes, $mode);
        }
    }

    public static function manualChange(IpAddress $ip, array $next, ?string $mode): void
    {
        if (! $ip->exists) {
            return;
        }
        $changes = [];
        foreach (['address', 'mac_address'] as $field) {
            if (! array_key_exists($field, $next)) {
                continue;
            }
            $value = $field === 'mac_address' ? MacAddress::normalize($next[$field]) : $next[$field];
            if ($ip->getOriginal($field) != $value) {
                $changes[$field === 'mac_address' ? 'mac' : 'address'] = $value;
            }
        }
        if (! $changes) {
            return;
        }
        $sources = UnifiObservation::forSite($ip->subnet->site_id)
            ->where(fn ($query) => $query->where('ip_address_id', $ip->id)->orWhere('address', $ip->getOriginal('address')))
            ->lockForUpdate()->get();
        foreach ($sources as $source) {
            $comparable = array_filter($changes, fn ($value, $field) => $field === 'mac'
                ? ! empty($source->data['mac']) : $source->ip_address_id === $ip->id, ARRAY_FILTER_USE_BOTH);
            if ($comparable) {
                self::rememberChange($source, $comparable, $mode);
            }
        }
    }

    private static function rememberChange(UnifiObservation $source, array $changes, ?string $mode): void
    {
        if (! in_array($mode, ['planned', 'manual'], true)) {
            throw ValidationException::withMessages(['mikrotik_edit_mode' => 'Il dato è alimentato anche da UniFi. Scegli Modifica prevista oppure Mantieni valore manuale.']);
        }
        $source->fill(['edit_mode' => $mode, 'expected' => $mode === 'planned' ? $changes : null, 'matched_at' => null])->save();
    }

    private static function recognizeExpected(UnifiSite $site): void
    {
        foreach (UnifiObservation::where('unifi_site_id', $site->id)->where('state', 'current')
            ->where('edit_mode', 'planned')->whereNull('matched_at')->get() as $source) {
            $matched = ! empty($source->expected);
            foreach ($source->expected ?? [] as $field => $value) {
                $actual = match ($field) {
                    'address' => $source->address,
                    'tag' => ($source->data['default'] ?? false) ? null : ($source->data['tag'] ?? null),
                    default => $source->data[$field] ?? null,
                };
                if ($actual != $value) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                $source->matched_at = now();
                if ($source->kind === 'network' && $source->linked) {
                    $anchor = $source->anchor ?? [];
                    foreach (['cidr', 'tag'] as $field) {
                        if (array_key_exists($field, $source->expected)) {
                            $anchor[$field] = $source->data[$field];
                        }
                    }
                    $source->anchor = $anchor;
                    if (array_key_exists('tag', $source->expected) && $source->subnet_id) {
                        $source->vlan_id = $source->subnet?->vlan_id;
                    }
                    $source->problem = null;
                    (new self)->associateNetwork($site->site, $source);
                }
                $source->save();
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Models\Device;
use App\Models\IpAddress;
use App\Models\DiscoveryEvent;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Services\IpConversionService;
use App\Services\MacAddressService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Services\MergePolicyService;
use App\Enums\DiscoverySource;
use Illuminate\Support\Facades\Schema;

final class IngestMikrotikArpAction
{
    /**
     * Execute ARP/DHCP ingest.
     * Expected payload keys: 'dhcp_leases' => [], 'arp_table' => []
     */
    public function execute(?Device $device, array $data): void
    {
        Log::info('IngestMikrotikArpAction: starting for device ' . ($device?->id ?? 'null'));

        if (! $device) return;

        $converter = app(IpConversionService::class);
        $subnets = Subnet::withoutTenantScope(fn () => Subnet::where('customer_id', $device->customer_id)->get());
        $now = Carbon::now();
        $supportsDiscover = Schema::hasColumn('subnets', 'discover_subnet');
        $supportsPing = Schema::hasColumn('subnets', 'ping_subnet');
        $supportsResolveDns = Schema::hasColumn('subnets', 'resolve_dns');

        // Pre-create subnets from interface addresses when provided (authoritative prefix)
        $ipAddressesSection = $data['ip_addresses']['items'] ?? $data['ip_addresses'] ?? null;
        if (is_array($ipAddressesSection)) {
            foreach ($ipAddressesSection as $ifaceAddr) {
                if (isset($ifaceAddr['disabled']) && filter_var($ifaceAddr['disabled'], FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }
                $cidr = $ifaceAddr['address'] ?? null;
                $ifaceName = $ifaceAddr['interface'] ?? null;
                if (! $cidr || ! $ifaceName) {
                    continue;
                }
                try {
                    [$ip, $mask] = explode('/', $cidr);
                    $mask = (int) $mask;
                    $network = $ifaceAddr['network'] ?? null;
                    $network = $network ?: long2ip(ip2long($ip) & -1 << (32 - $mask));
                } catch (\Throwable $e) {
                    continue;
                }

                $port = $device->ports()->where('name', $ifaceName)->first();
                $vlanId = $this->resolveVlanId($device, $port?->pvid ?? null);
                if ($vlanId === null) {
                    $vlanId = $this->resolveVlanIdFromInterfaceName($device, $ifaceName);
                }
                $isBlacklistedSubnet = \App\Models\SubnetBlacklist::withoutTenantScope(fn () => \App\Models\SubnetBlacklist::where('customer_id', $device->customer_id)->where('subnet_cidr', $network . '/' . $mask)->exists());
                if ($isBlacklistedSubnet) {
                    continue;
                }

                try {
                    $attributes = [
                        'subnet' => $converter->toDecimal($network),
                        'mask' => $mask,
                        'customer_id' => $device->customer_id,
                    ];
                    $values = [
                        'cidr' => $network . '/' . $mask,
                        'vlan_id' => $vlanId,
                        'customer_id' => $device->customer_id,
                        'scan_agent_id' => $device->scan_agent_id,
                    ];
                    // Conditionally include discovery flags if present in schema
                    if ($supportsDiscover) {
                        $values['discover_subnet'] = true;
                    }
                    if ($supportsPing) {
                        $values['ping_subnet'] = false;
                    }
                    if ($supportsResolveDns) {
                        $values['resolve_dns'] = false;
                    }
                    $subnet = $this->firstOrRestoreSubnet($attributes, $values);
                    $subnets->push($subnet);
                } catch (\Throwable $e) {
                    Log::warning('IngestMikrotikArpAction: subnet creation from ip_addresses failed ' . $e->getMessage());
                }
            }
        }

        // Normalizza sorgenti in base al PDL: preferiamo device_result (dhcp_leases.items / arp.items)
        $dhcpSection = $data['dhcp_leases'] ?? null;
        if (is_array($dhcpSection) && isset($dhcpSection['items']) && is_array($dhcpSection['items'])) {
            $dhcp = $dhcpSection['items'];
        } else {
            // Fallback legacy
            $dhcp = $data['dhcp_leases'] ?? $data['dhcp'] ?? [];
        }

        // DHCP leases handling
        if (is_array($dhcp)) {
            DB::transaction(function() use ($dhcp, $device, $converter, $subnets, $now) {
                foreach ($dhcp as $lease) {
                    $address = $lease['address'] ?? $lease['ip'] ?? null;
                    $hostname = $lease['host-name'] ?? $lease['hostname'] ?? null;
                    $dynamic = array_key_exists('dynamic', $lease) ? (bool) $lease['dynamic'] : null;
                    $description = $lease['comment'] ?? 'Discovered by DHCP';

                    $this->upsertDiscoveredIp(
                        $device,
                        entry: $lease,
                        address: $address,
                        macRaw: $lease['mac-address'] ?? $lease['mac'] ?? null,
                        hostname: $hostname,
                        type: $dynamic === false ? \App\Enums\IpAddressType::Reserved : \App\Enums\IpAddressType::DHCP,
                        description: $description,
                        subnets: $subnets,
                        converter: $converter,
                        now: $now,
                        orphanConflict: null,
                    );
                }
            }, attempts: 3);  // Retry on deadlock
        }

        // ARP table handling
        $arpSection = $data['arp'] ?? null;
        if (is_array($arpSection) && isset($arpSection['items']) && is_array($arpSection['items'])) {
            $arp = $arpSection['items'];
        } else {
            // Fallback legacy
            $arp = $data['arp_table'] ?? $data['arp'] ?? [];
        }

        if (is_array($arp)) {
            DB::transaction(function() use ($arp, $device, $converter, $subnets, $now) {
                foreach ($arp as $r) {
                    $ipAddr = $r['address'] ?? $r['ip'] ?? null;
                    $hostname = $r['hostname'] ?? null;

                    $this->upsertDiscoveredIp(
                        $device,
                        entry: $r,
                        address: $ipAddr,
                        macRaw: $r['mac-address'] ?? $r['mac'] ?? null,
                        hostname: $hostname,
                        type: \App\Enums\IpAddressType::Host,
                        description: 'Discovered via ARP',
                        subnets: $subnets,
                        converter: $converter,
                        now: $now,
                        orphanConflict: 'ORPHAN_IP',
                    );
                }
            }, attempts: 3);  // Retry on deadlock
        }

        Log::info('IngestMikrotikArpAction: completed for device ' . $device->id);
    }

    /**
     * Shared upsert logic for DHCP/ARP entries to avoid duplication and keep
     * tenancy-safe operations consistent.
     */
    private function upsertDiscoveredIp(
        Device $device,
        array $entry,
        ?string $address,
        ?string $macRaw,
        ?string $hostname,
        \App\Enums\IpAddressType $type,
        string $description,
        \Illuminate\Support\Collection|array $subnets,
        IpConversionService $converter,
        \Illuminate\Support\Carbon $now,
        ?string $orphanConflict,
    ): void {
        if (! $address) {
            return;
        }

        try {
            $dec = $converter->toDecimal($address);
        } catch (\Throwable $e) {
            Log::warning('IngestMikrotikArpAction: invalid IP ' . $address);
            return;
        }

        $mac = MacAddressService::normalize($macRaw);
        if (! $mac) {
            Log::warning('IngestMikrotikArpAction: invalid or missing MAC for IP ' . $address);
            return;
        }

        $ip = IpAddress::where('customer_id', $device->customer_id)
            ->where('ip_addr', (string) $dec)
            ->lockForUpdate()
            ->first();

        if ($ip) {
            // Applica MergePolicyService per campi mac_address / hostname
            /** @var MergePolicyService $merge */
            $merge = app(MergePolicyService::class);

            $local = [
                'mac_address' => $ip->mac_address,
                'hostname' => $ip->hostname,
            ];

            $remote = [
                'mac_address' => $mac,
                'hostname' => $hostname,
            ];

            $merge->mergeIpAddress($ip, $local, $remote, DiscoverySource::Rest);

            $ip->last_seen = $now;
            $ip->type = $type;
            $ip->markSeen($now);
            $ip->save();

            return;
        }

        $mappedSubnet = null;
        $portName = $entry['interface'] ?? $entry['ifname'] ?? null;
        $port = $portName ? ($device->ports()->where('name', $portName)->first() ?? null) : null;
        $portVlanId = $this->resolveVlanId($device, $port?->pvid ?? null);
        if ($portVlanId === null && $portName) {
            $portVlanId = $this->resolveVlanIdFromInterfaceName($device, $portName);
        }

        foreach ($subnets as $s) {
            try {
                if ($converter->isIpInSubnet((string) $dec, (string) $s->subnet, (int) $s->mask)) {
                    $mappedSubnet = $s;
                    break;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // If no subnet matches but the port has a PVID and a VLAN exists, create a /24 subnet for that VLAN
        // (unless the VLAN/subnet is blacklisted).
        if (! $mappedSubnet && $port && $port->pvid) {
            $pvid = (int) $port->pvid;

            $isBlacklistedVlan = \App\Models\VlanBlacklist::withoutTenantScope(fn () => \App\Models\VlanBlacklist::where('customer_id', $device->customer_id)->where('vlan_number', $pvid)->exists());
            if (! $isBlacklistedVlan) {
                $vlan = Vlan::withoutTenantScope(fn () => Vlan::firstOrCreate(
                    ['number' => $pvid, 'customer_id' => $device->customer_id],
                    ['name' => 'VLAN ' . $pvid]
                ));
                $ipAddr = $converter->fromDecimal((string) $dec)?->toString();
                if ($vlan && $ipAddr) {
                    $mask = 24;
                    $networkLong = ip2long($ipAddr) & -1 << (32 - $mask);
                    $network = long2ip($networkLong);
                    $cidr = $network . '/' . $mask;

                    $isBlacklistedSubnet = \App\Models\SubnetBlacklist::withoutTenantScope(fn () => \App\Models\SubnetBlacklist::where('customer_id', $device->customer_id)->where('subnet_cidr', $cidr)->exists());
                    if (! $isBlacklistedSubnet) {
                        try {
                            $attributes = [
                                'subnet' => $converter->toDecimal($network),
                                'mask' => $mask,
                                'customer_id' => $device->customer_id,
                            ];

                            $values = [
                                'cidr' => $cidr,
                                'vlan_id' => $vlan->id,
                                'customer_id' => $device->customer_id,
                                'scan_agent_id' => $device->scan_agent_id,
                            ];
                            if ($supportsDiscover) {
                                $values['discover_subnet'] = true;
                            }
                            if ($supportsPing) {
                                $values['ping_subnet'] = false;
                            }
                            if ($supportsResolveDns) {
                                $values['resolve_dns'] = false;
                            }

                            $mappedSubnet = $this->firstOrRestoreSubnet($attributes, $values);
                        } catch (\Throwable $e) {
                            Log::warning('IngestMikrotikArpAction: subnet creation failed ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        if ($mappedSubnet) {
            $payload = [
                'subnet_id' => $mappedSubnet->getKey(),
                'ip_addr' => (string) $dec,
                'mac_address' => $mac,
                'hostname' => $hostname,
                'description' => $description,
                'customer_id' => $mappedSubnet->customer_id,
                'vlan_id' => $portVlanId ?? $mappedSubnet->vlan_id,
                'type' => $type,
                'state' => 2,
                'last_seen' => $now,
                'device_id' => $device->id,
                'port' => $portName,
            ];

            try {
                $ipCreated = IpAddress::create($payload);
                $ipCreated->markSeen($now);
                $ipCreated->save();
            } catch (\Throwable $e) {
                Log::warning('IngestMikrotikArpAction: ip create failed ' . $e->getMessage(), [
                    'payload' => $payload,
                ]);
            }
            return;
        }

        $scanAgentId = (int) ($device->scan_agent_id ?? 0);
        if ($scanAgentId <= 0) {
            throw new \RuntimeException('IngestMikrotikArpAction: device is missing scan_agent_id; cannot create DiscoveryEvent safely.');
        }
        if (empty($device->customer_id)) {
            throw new \RuntimeException('IngestMikrotikArpAction: device is missing customer_id; cannot create DiscoveryEvent safely.');
        }

        DiscoveryEvent::create([
            'customer_id' => (int) $device->customer_id,
            'scan_agent_id' => $scanAgentId,
            'ip_addr' => (string) $dec,
            'mac_address_current' => $mac,
            'hostname_current' => $hostname,
            'status' => DiscoveryEvent::STATUS_PENDING,
            'conflict_type' => $orphanConflict,
            'payload_raw' => $entry,
        ]);
    }

    /**
     * Resolve VLAN model id from a VLAN number and customer; create it if missing.
     */
    private function resolveVlanId(Device $device, ?int $vlanNumber): ?int
    {
        if (! $vlanNumber) {
            return null;
        }

        $vlan = Vlan::withoutTenantScope(fn () => Vlan::firstOrCreate(
            ['number' => (int) $vlanNumber, 'customer_id' => $device->customer_id],
            ['name' => 'VLAN ' . $vlanNumber]
        ));

        return $vlan?->id;
    }

    /**
     * Best-effort derivation of VLAN number from interface name (e.g. vlan60, VLAN_835).
     */
    private function resolveVlanIdFromInterfaceName(Device $device, string $ifaceName): ?int
    {
        if (preg_match('/vlan[_-]?(\d{1,4})/i', $ifaceName, $m)) {
            $num = (int) $m[1];
            return $this->resolveVlanId($device, $num);
        }

        return null;
    }

    /**
     * Find or create a subnet using attributes; if a soft-deleted row exists, restore it.
     */
    private function firstOrRestoreSubnet(array $attributes, array $values): Subnet
    {
        return Subnet::withoutTenantScope(function () use ($attributes, $values) {
            $query = Subnet::withTrashed()->where($attributes);
            $existing = $query->first();
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                // Update mutable fields (vlan, scan_agent, flags) without touching subnet/mask/customer
                $existing->fill($values);
                $existing->save();
                return $existing;
            }

            return Subnet::create(array_merge($attributes, $values));
        });
    }
}

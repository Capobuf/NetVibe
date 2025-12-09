<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Models\Device;
use App\Models\IpAddress;
use App\Models\DiscoveryEvent;
use App\Models\Subnet;
use App\Services\IpConversionService;
use App\Services\MacAddressService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Services\MergePolicyService;
use App\Enums\DiscoverySource;

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

        $ip = IpAddress::where('ip_addr', (string) $dec)
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
            $ip->save();

            return;
        }

        $mappedSubnet = null;
        $portName = $entry['interface'] ?? $entry['ifname'] ?? null;
        $port = $portName ? ($device->ports()->where('name', $portName)->first() ?? null) : null;

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
        if (! $mappedSubnet && $port && $port->pvid) {
            $vlan = \App\Models\Vlan::where('number', $port->pvid)->where('customer_id', $device->customer_id)->first();
            $ipAddr = $converter->fromDecimal((string) $dec)?->toString();
            if ($vlan && $ipAddr) {
                $mask = 24;
                $networkLong = ip2long($ipAddr) & -1 << (32 - $mask);
                $network = long2ip($networkLong);
                try {
                    $attributes = [
                        'subnet' => $converter->toDecimal($network),
                        'mask' => $mask,
                    ];

                    $values = [
                        'cidr' => $network . '/' . $mask,
                        'vlan_id' => $vlan->id,
                        'customer_id' => $device->customer_id,
                        'scan_agent_id' => $device->scan_agent_id,
                        'discover_subnet' => true,
                        'ping_subnet' => false,
                        'resolve_dns' => false,
                    ];

                    $mappedSubnet = \App\Models\Subnet::withoutTenantScope(fn () => \App\Models\Subnet::firstOrCreate($attributes, $values));
                } catch (\Throwable $e) {
                    Log::warning('IngestMikrotikArpAction: subnet creation failed ' . $e->getMessage());
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
                'type' => $type,
                'state' => 2,
                'last_seen' => $now,
                'device_id' => $device->id,
                'port' => $portName,
            ];

            try {
                IpAddress::create($payload);
            } catch (\Throwable $e) {
                Log::warning('IngestMikrotikArpAction: ip create failed ' . $e->getMessage(), [
                    'payload' => $payload,
                ]);
            }
            return;
        }

        DiscoveryEvent::create([
            'scan_agent_id' => null,
            'ip_addr' => (string) $dec,
            'mac_address_current' => $mac,
            'hostname_current' => $hostname,
            'status' => DiscoveryEvent::STATUS_PENDING,
            'conflict_type' => $orphanConflict,
            'payload_raw' => $entry,
        ]);
    }
}

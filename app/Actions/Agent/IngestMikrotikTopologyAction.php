<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Models\Device;
use App\Models\IpAddress;
use App\Models\DiscoveryEvent;
use App\Services\MacAddressService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class IngestMikrotikTopologyAction
{
    public function execute(?Device $device, array $data): void
    {
        Log::info('IngestMikrotikTopologyAction: ingesting topology for device ' . ($device->id ?? 'null'));

        if (! $device) return;

        $now = \Illuminate\Support\Carbon::now();

        // Interfaces
        $interfaces = $data['interfaces'] ?? $data['interface'] ?? $data['result']['interfaces'] ?? [];
        if (is_array($interfaces)) {
            foreach ($interfaces as $iface) {
                $name = $iface['name'] ?? $iface['interface'] ?? null;
                if (! $name) continue;

                $port = $device->ports()->firstOrCreate(['name' => $name], ['device_id' => $device->id]);

                // Map basic properties if present
                $macRaw = $iface['mac-address'] ?? ($iface['mac'] ?? null);
                if (! empty($macRaw)) {
                    $port->mac_address = $macRaw;
                }

                // Map physical / link attributes
                if (! empty($iface['mtu'])) {
                    // store in status (best-effort) if MTU field isn't available elsewhere. Keep type intact.
                    $port->mtu = $iface['mtu'] ?? null;
                }

                if (! empty($iface['speed'])) {
                    // speed may be string like "1000Mbps" or numeric; store as-is
                    $port->speed = $iface['speed'];
                }

                if (! empty($iface['duplex'])) {
                    $port->duplex = $iface['duplex'];
                }

                if (! empty($iface['external_type'])) {
                    $port->external_type = $iface['external_type'];
                }

                $description = $iface['description'] ?? ($iface['desc'] ?? null);
                if (! empty($description)) {
                    $port->description = $description;
                }

                // VLAN-related attributes
                $vlanMode = $iface['vlan-mode'] ?? ($iface['vlan_mode'] ?? ($iface['vlanMode'] ?? null));
                if (! empty($vlanMode)) {
                    $port->vlan_mode = $vlanMode;
                }

                if (! empty($iface['pvid'])) {
                    // pvid might be string; cast to int
                    $port->pvid = (int) $iface['pvid'];
                }

                $taggedRaw = $iface['tagged-vlans'] ?? ($iface['tagged_vlans'] ?? ($iface['taggedVlans'] ?? null));
                if (! empty($taggedRaw)) {
                    $tv = $taggedRaw;
                    // Normalize various input shapes: CSV string, array of ints/strings
                    if (is_string($tv)) {
                        $arr = array_filter(array_map('trim', explode(',', $tv)), fn($v) => $v !== '');
                        $arr = array_values(array_map(fn($v) => is_numeric($v) ? (int) $v : $v, $arr));
                    } elseif (is_array($tv)) {
                        $arr = array_values(array_filter(array_map(fn($v) => is_numeric($v) ? (int) $v : $v, $tv), fn($v) => $v !== null && $v !== ''));
                    } else {
                        $arr = null;
                    }

                    if ($arr !== null) {
                        $port->tagged_vlans = $arr;
                    }
                }

                $status = $iface['running'] ?? $iface['status'] ?? null;
                if ($status !== null) {
                    // map truthy/boolean to active/down
                    $running = filter_var($status, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    $port->status = ($running === null) ? $port->status : ($running ? 'active' : 'down');
                }

                $port->last_seen = $now;

                // Persist port with casts intact
                try {
                    $port->save();
                } catch (\Throwable $e) {
                    Log::warning('IngestMikrotikTopologyAction: saving port failed: ' . $e->getMessage());
                }
            }
        }

        // VLAN table: create VLANs and map ports
        $vlans = $data['bridge_vlan'] ?? $data['bridge']['vlan'] ?? $data['result']['bridge_vlan'] ?? [];
        if (is_array($vlans)) {
            foreach ($vlans as $entry) {
                $vlanIds = $this->parseList($entry['vlan-ids'] ?? $entry['vlan_ids'] ?? $entry['vlans'] ?? null);
                if (empty($vlanIds)) {
                    continue;
                }

                $taggedPorts = $this->parseList($entry['tagged'] ?? $entry['tagged-ports'] ?? $entry['tagged_ports'] ?? $entry['ports'] ?? null);
                $untaggedPorts = $this->parseList($entry['untagged'] ?? $entry['untagged-ports'] ?? $entry['untagged_ports'] ?? null);

                foreach ($vlanIds as $vid) {
                    $nameSource = $entry['comment'] ?? $untaggedPorts[0] ?? $taggedPorts[0] ?? null;
                    $vlanName = $nameSource ? (string) $nameSource : 'VLAN ' . $vid;

                    $vlan = \App\Models\Vlan::withoutTenantScope(fn () => \App\Models\Vlan::firstOrCreate(
                        ['number' => $vid, 'customer_id' => $device->customer_id],
                        ['name' => $vlanName]
                    ));

                    // Map PVID/tagged to ports
                    foreach ($untaggedPorts as $pname) {
                        $port = $device->ports()->where('name', $pname)->first();
                        if ($port) {
                            $port->pvid = $vid;
                            $port->save();
                        }
                    }
                    foreach ($taggedPorts as $pname) {
                        $port = $device->ports()->where('name', $pname)->first();
                        if ($port) {
                            $tagged = $port->tagged_vlans ?? [];
                            if (! in_array($vid, $tagged, true)) {
                                $tagged[] = $vid;
                                $port->tagged_vlans = array_values($tagged);
                                $port->save();
                            }
                        }
                    }
                }
            }
        }

        // Bridge hosts (/rest/interface/bridge/host) — map MAC -> IPs/ports
        $bridgeHosts = $data['bridge_hosts'] ?? $data['bridge_host'] ?? $data['bridge']['host'] ?? $data['result']['bridge_hosts'] ?? [];
        $converter = app(\App\Services\IpConversionService::class);
        $connectionEntries = [];
        $addConnection = function (?string $mac, ?string $iface, ?string $addr, ?string $hostname, array $payload = []) use (&$connectionEntries) {
            if (! $mac || ! $iface) {
                return;
            }

            $connectionEntries[] = [
                'mac' => $mac,
                'iface' => $iface,
                'addr' => $addr,
                'hostname' => $hostname,
                'payload' => $payload ?: null,
            ];
        };

        if (is_array($bridgeHosts)) {
            foreach ($bridgeHosts as $h) {
                if (! empty($h['local']) && filter_var($h['local'], FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $mac = MacAddressService::normalize($h['mac-address'] ?? $h['mac'] ?? null);
                $iface = $h['interface'] ?? null;
                $addr = $h['ip-address'] ?? $h['address'] ?? null;

                $addConnection($mac, $iface, $addr, $h['hostname'] ?? null, $h);
            }
        }

        // IP neighbor table — try to link neighbors to existing ports/devices
        $neighbors = $data['ip_neighbors'] ?? $data['ip_neighbor'] ?? $data['ip']['neighbor'] ?? $data['result']['ip_neighbors'] ?? [];
        if (is_array($neighbors)) {
            foreach ($neighbors as $n) {
                $mac = MacAddressService::normalize($n['mac-address'] ?? $n['mac'] ?? null);
                $iface = $n['interface'] ?? $n['ifname'] ?? null;
                $addr = $n['address'] ?? $n['ip'] ?? null;

                $addConnection($mac, $iface, $addr, $n['hostname'] ?? null, $n);
            }
        }

        if (! empty($connectionEntries)) {
            DB::transaction(function () use ($connectionEntries, $device, $converter, $now) {
                foreach ($connectionEntries as $entry) {
                    $mac = $entry['mac'];
                    $iface = $entry['iface'];
                    $addr = $entry['addr'] ?? null;
                    $hostname = $entry['hostname'] ?? null;
                    $payloadRaw = $entry['payload'] ?? $entry;

                    $this->linkPorts($device, $mac, $iface);

                    if ($addr) {
                        try {
                            $dec = $converter->toDecimal($addr);
                        } catch (\Throwable $e) {
                            $dec = null;
                        }

                        if ($dec) {
                            $ip = IpAddress::where('ip_addr', (string) $dec)->first();
                            if ($ip) {
                                $ip->port = $iface;
                                $ip->mac_address = $mac;
                                $ip->last_seen = $now;
                                $ip->save();
                            } else {
                                DiscoveryEvent::create([
                                    'scan_agent_id' => null,
                                    'ip_addr' => (string) $dec,
                                    'mac_address_current' => $mac,
                                    'hostname_current' => $hostname,
                                    'status' => DiscoveryEvent::STATUS_PENDING,
                                    'conflict_type' => 'ORPHAN_IP',
                                    'payload_raw' => $payloadRaw,
                                ]);
                            }
                        }
                    }
                }
            }, attempts: 3);
        }

        Log::info('IngestMikrotikTopologyAction: completed for device ' . $device->id);
    }

    /**
     * Link a local interface to a remote port (by MAC), ensuring the mapping is
     * bidirectional when both ports are present.
     */
    private function linkPorts(Device $device, string $normalizedMac, ?string $iface): void
    {
        if (! $iface) {
            return;
        }

        $remotePort = \App\Models\Port::where('mac_address', $normalizedMac)->first();
        $localPort = $device->ports()->where('name', $iface)->first();

        if ($localPort && $remotePort) {
            $localPort->connected_to_port_id = $remotePort->id;
            $localPort->save();

            if ($remotePort->connected_to_port_id !== $localPort->id) {
                $remotePort->connected_to_port_id = $localPort->id;
                $remotePort->save();
            }
        }
    }

    private function parseList($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), fn ($v) => $v !== '' && $v !== null));
        }

        if (is_string($value)) {
            $parts = array_map('trim', preg_split('/[,; ]+/', $value));
            return array_values(array_filter($parts, fn ($v) => $v !== ''));
        }

        return [];
    }
}

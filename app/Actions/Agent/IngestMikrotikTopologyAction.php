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
        // Prefer device_result.interfaces.items; fallback ai payload legacy storici.
        $interfacesSection = $data['interfaces'] ?? null;
        if (is_array($interfacesSection) && isset($interfacesSection['items']) && is_array($interfacesSection['items'])) {
            $interfaces = $interfacesSection['items'];
        } else {
            $interfaces = $data['interfaces'] ?? $data['interface'] ?? $data['result']['interfaces'] ?? [];
        }
        if (is_array($interfaces)) {
            foreach ($interfaces as $iface) {
                $name = $iface['name'] ?? $iface['interface'] ?? null;
                if (! $name) return;

                $port = $device->ports()->firstOrCreate(['name' => $name], ['device_id' => $device->id]);

                // Map basic properties if present
                $macRaw = $iface['mac-address'] ?? ($iface['mac'] ?? null);
                if (! empty($macRaw)) {
                    $port->mac_address = MacAddressService::normalize($macRaw) ?? $macRaw;
                }

                // Map physical / link attributes (best-effort; device_result for Fase 1 espone speed_bps/description)
                if (! empty($iface['mtu'])) {
                    $port->mtu = $iface['mtu'] ?? null;
                }

                if (! empty($iface['speed'])) {
                    $port->speed = $iface['speed'];
                } elseif (isset($iface['speed_bps'])) {
                    $port->speed = $iface['speed_bps'];
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

                $status = $iface['running'] ?? $iface['status'] ?? ($iface['oper_status'] ?? null);
                if ($status !== null) {
                    // map truthy/boolean to active/down
                    $running = filter_var($status, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    $port->status = ($running === null) ? $port->status : ($running ? 'active' : 'down');
                }

                $port->last_seen = $now;
                $port->markSeen($now);

                // Persist port with casts intact
                try {
                    $port->save();
                } catch (\Throwable $e) {
                    Log::warning('IngestMikrotikTopologyAction: saving port failed: ' . $e->getMessage());
                }
            }
        }

        // VLAN table: create VLANs and map ports
        // Per-port VLAN membership tramite bridge_vlan è rimandata; qui usiamo solo payload legacy se presente.
        $vlans = $data['bridge_vlan'] ?? $data['bridge']['vlan'] ?? $data['result']['bridge_vlan'] ?? [];
        if (is_array($vlans)) {
            foreach ($vlans as $entry) {
                $vlanIds = $this->parseList($entry['vlan-ids'] ?? $entry['vlan_ids'] ?? $entry['vlans'] ?? null);
                if (empty($vlanIds)) {
                    return;
                }

                $taggedPorts = $this->parseList($entry['tagged'] ?? $entry['tagged-ports'] ?? $entry['tagged_ports'] ?? $entry['ports'] ?? null);
                $untaggedPorts = $this->parseList($entry['untagged'] ?? $entry['untagged-ports'] ?? $entry['untagged_ports'] ?? null);

                foreach ($vlanIds as $vid) {
                    $nameSource = $entry['comment'] ?? $untaggedPorts[0] ?? $taggedPorts[0] ?? null;
                    $vlanName = $nameSource ? (string) $nameSource : 'VLAN ' . $vid;


                    $vid = (int) $vid;
                    $isBlacklistedVlan = \App\Models\VlanBlacklist::withoutTenantScope(fn () => \App\Models\VlanBlacklist::where('customer_id', $device->customer_id)->where('vlan_number', $vid)->exists());
                    if ($isBlacklistedVlan) {
                        continue;
                    }

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
        $bridgeSection = $data['bridge_hosts'] ?? null;
        if (is_array($bridgeSection) && isset($bridgeSection['items']) && is_array($bridgeSection['items'])) {
            $bridgeHosts = $bridgeSection['items'];
        } else {
            $bridgeHosts = $data['bridge_hosts'] ?? $data['bridge_host'] ?? $data['bridge']['host'] ?? $data['result']['bridge_hosts'] ?? [];
        }
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
                    return;
                }

                $mac = MacAddressService::normalize($h['mac-address'] ?? $h['mac'] ?? null);
                $iface = $h['interface'] ?? null;
                $addr = $h['ip-address'] ?? $h['address'] ?? null;

                $addConnection($mac, $iface, $addr, $h['hostname'] ?? null, $h);
            }
        }

        // IP neighbor table — try to link neighbors to existing ports/devices
        $neighborsSection = $data['neighbors'] ?? null;
        if (is_array($neighborsSection) && isset($neighborsSection['items']) && is_array($neighborsSection['items'])) {
            $neighbors = $neighborsSection['items'];
        } else {
            $neighbors = $data['ip_neighbors'] ?? $data['ip_neighbor'] ?? $data['ip']['neighbor'] ?? $data['result']['ip_neighbors'] ?? [];
        }
        if (is_array($neighbors)) {
            foreach ($neighbors as $n) {
                $mac = MacAddressService::normalize($n['mac-address'] ?? $n['mac'] ?? $n['remote_mac'] ?? null);
                $iface = $n['interface'] ?? $n['ifname'] ?? $n['local_interface'] ?? null;
                $addr = $n['address'] ?? $n['ip'] ?? $n['remote_ip'] ?? null;

                $hostname = $n['hostname'] ?? $n['remote_name'] ?? null;

                $addConnection($mac, $iface, $addr, $hostname, $n);
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
                            $ip = IpAddress::where('customer_id', $device->customer_id)->where('ip_addr', (string) $dec)->first();
                            if ($ip) {
                                $ip->port = $iface;
                                $ip->mac_address = $mac;
                                $ip->last_seen = $now;
                                $ip->markSeen($now);
                                $ip->save();
                            } else {
                                $scanAgentId = (int) ($device->scan_agent_id ?? 0);
                                if ($scanAgentId <= 0) {
                                    throw new \RuntimeException('IngestMikrotikTopologyAction: device is missing scan_agent_id; cannot create DiscoveryEvent safely.');
                                }
                                if (empty($device->customer_id)) {
                                    throw new \RuntimeException('IngestMikrotikTopologyAction: device is missing customer_id; cannot create DiscoveryEvent safely.');
                                }

                                DiscoveryEvent::create([
                                    'customer_id' => (int) $device->customer_id,
                                    'scan_agent_id' => $scanAgentId,
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

        $remotePort = \App\Models\Port::query()
            ->where('mac_address', $normalizedMac)
            ->whereHas('device', fn ($q) => $q->where('customer_id', $device->customer_id))
            ->first();
        $localPort = $device->ports()->where('name', $iface)->first();

        if ($localPort && $remotePort) {
            // avoid self-linking and cross-device loops
            if ($localPort->id === $remotePort->id) {
                return;
            }
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

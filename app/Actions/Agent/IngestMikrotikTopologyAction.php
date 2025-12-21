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
                if (! $name) {
                    Log::warning('IngestMikrotikTopologyAction: interface without name skipped', ['device_id' => $device->id]);
                    continue;
                }

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

                $status = $iface['running'] ?? $iface['status'] ?? ($iface['oper_status'] ?? null);
                if ($status !== null) {
                    // map common string values and booleans to active/down
                    if (is_string($status) && strtolower($status) === 'up') {
                        $port->status = 'active';
                    } elseif (is_string($status) && strtolower($status) === 'down') {
                        $port->status = 'down';
                    } else {
                        $running = filter_var($status, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($running !== null) {
                            $port->status = $running ? 'active' : 'down';
                        }
                    }
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

        // VLAN list (device_result.vlans.items) — Fase 1 non include tagging per-port
        $vlansSection = $data['vlans'] ?? null;
        $vlanItems = [];
        if (is_array($vlansSection) && isset($vlansSection['items']) && is_array($vlansSection['items'])) {
            $vlanItems = $vlansSection['items'];
        } elseif (is_array($vlansSection)) {
            $vlanItems = $vlansSection;
        }
        foreach ($vlanItems as $item) {
            $vid = $item['vlan_id'] ?? $item['vlanid'] ?? $item['id'] ?? null;
            if ($vid === null) {
                continue;
            }
            $vid = (int) $vid;
            $name = $item['name'] ?? ('VLAN ' . $vid);
            $isBlacklisted = \App\Models\VlanBlacklist::withoutTenantScope(fn () => \App\Models\VlanBlacklist::where('customer_id', $device->customer_id)->where('vlan_number', $vid)->exists());
            if ($isBlacklisted) {
                continue;
            }
            \App\Models\Vlan::withoutTenantScope(fn () => \App\Models\Vlan::firstOrCreate(
                ['number' => $vid, 'customer_id' => $device->customer_id],
                ['name' => $name]
            ));
        }

        // Prefer structured bridges dataset when available
        $bridges = [];
        $bridgesSection = $data['bridges']['items'] ?? $data['bridges'] ?? null;
        if (is_array($bridgesSection) && isset($bridgesSection[0]) || (isset($bridgesSection['items']) && is_array($bridgesSection['items']))) {
            $bridges = isset($bridgesSection['items']) ? $bridgesSection['items'] : $bridgesSection;
        }

        $bridgePortsSection = $data['bridge_ports'] ?? $data['bridge']['ports'] ?? $data['bridge_port'] ?? $data['result']['bridge_ports'] ?? [];
        $bridgeVlanSection = $data['bridge_vlan'] ?? $data['bridge']['vlan'] ?? $data['bridgeVlan'] ?? $data['result']['bridge_vlan'] ?? [];

        // Map per-port PVID and frame-types
        $portPvid = [];
        $portFrameTypes = [];
        if (is_array($bridgePortsSection)) {
            $items = isset($bridgePortsSection['items']) && is_array($bridgePortsSection['items'])
                ? $bridgePortsSection['items']
                : $bridgePortsSection;
            foreach ($items as $bp) {
                $pname = $bp['interface'] ?? $bp['name'] ?? null;
                if (! $pname) {
                    continue;
                }
                if (isset($bp['pvid'])) {
                    $portPvid[$pname] = (int) $bp['pvid'];
                }
                if (isset($bp['frame-types']) || isset($bp['frame_types'])) {
                    $portFrameTypes[$pname] = $bp['frame-types'] ?? $bp['frame_types'];
                }
            }
        }

        // Apply PVID/native VLAN where allowed
        if (! empty($portPvid)) {
            foreach ($portPvid as $pname => $pvid) {
                $port = $device->ports()->where('name', $pname)->first();
                if (! $port) {
                    continue;
                }
                $frameTypes = $portFrameTypes[$pname] ?? null;
                if ($frameTypes === 'admit-only-vlan-tagged') {
                    continue; // no native VLAN
                }
                $port->pvid = $pvid;
                $port->save();
            }
        }

        // Process structured bridges dataset if present (bridge-aware mapping)
        $usedBridgesDataset = false;
        if (! empty($bridges)) {
            $usedBridgesDataset = true;
            foreach ($bridges as $bridge) {
                $bridgePorts = $bridge['ports'] ?? [];
                $bridgeVlans = $bridge['vlans'] ?? [];
                $bridgeName = $bridge['name'] ?? null;
                $bridgePvid = $bridge['pvid'] ?? null;

                // Ensure the bridge interface itself exists as a port and inherits its PVID (so ARP on the bridge has a PVID hint)
                if ($bridgeName) {
                    $bridgePort = $device->ports()->firstOrCreate(['name' => $bridgeName], ['device_id' => $device->id]);
                    if ($bridgePvid !== null) {
                        $bridgePort->pvid = (int) $bridgePvid;
                        $bridgePort->save();
                    }
                }

                $portFrameMap = [];
                foreach ($bridgePorts as $bp) {
                    $pname = $bp['name'] ?? null;
                    if (! $pname) {
                        continue;
                    }

                    $port = $device->ports()->firstOrCreate(['name' => $pname], ['device_id' => $device->id]);
                    $frameTypes = $bp['frame_types'] ?? null;
                    $portFrameMap[$pname] = $frameTypes;

                    if (! empty($bp['pvid']) && $frameTypes !== 'admit-only-vlan-tagged') {
                        $port->pvid = (int) $bp['pvid'];
                    }

                    if (! empty($bp['tagged']) && is_array($bp['tagged'])) {
                        $tagged = $port->tagged_vlans ?? [];
                        foreach ($bp['tagged'] as $vid) {
                            if (! in_array((int) $vid, $tagged, true)) {
                                $tagged[] = (int) $vid;
                            }
                        }
                        $port->tagged_vlans = array_values($tagged);
                    }

                    $port->save();
                }

                foreach ($bridgeVlans as $entry) {
                    $vid = $entry['vlan_id'] ?? ($entry['vlanid'] ?? null);
                    if ($vid === null) {
                        continue;
                    }
                    $vid = (int) $vid;
                    $isBlacklistedVlan = \App\Models\VlanBlacklist::withoutTenantScope(fn () => \App\Models\VlanBlacklist::where('customer_id', $device->customer_id)->where('vlan_number', $vid)->exists());
                    if ($isBlacklistedVlan) {
                        continue;
                    }

                    $vlan = \App\Models\Vlan::withoutTenantScope(fn () => \App\Models\Vlan::firstOrCreate(
                        ['number' => $vid, 'customer_id' => $device->customer_id],
                        ['name' => $entry['name'] ?? ('VLAN ' . $vid)]
                    ));

                    $taggedPorts = $entry['tagged_ports'] ?? [];
                    $untaggedPorts = $entry['untagged_ports'] ?? [];

                    foreach ($untaggedPorts as $pname) {
                        $port = $device->ports()->where('name', $pname)->first();
                        if ($port) {
                            $frameTypes = $portFrameMap[$pname] ?? null;
                            if ($frameTypes !== 'admit-only-vlan-tagged') {
                                $port->pvid = $vid;
                                $port->save();
                            }
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

        // VLAN table: create VLANs and map ports using bridge_vlan dataset
        if (! $usedBridgesDataset) {
            $vlans = $bridgeVlanSection;
            if (is_array($vlans)) {
                foreach ($vlans as $entry) {
                    if (isset($entry['disabled']) && filter_var($entry['disabled'], FILTER_VALIDATE_BOOLEAN)) {
                        continue;
                    }
                    $vlanIds = $this->parseList($entry['vlan-ids'] ?? $entry['vlan_ids'] ?? $entry['vlans'] ?? null);
                    if (empty($vlanIds)) {
                        Log::warning('IngestMikrotikTopologyAction: vlan entry without vlan-ids skipped', ['device_id' => $device->id]);
                        continue;
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
                                $frameTypes = $portFrameTypes[$pname] ?? null;
                                if ($frameTypes !== 'admit-only-vlan-tagged') {
                                    $port->pvid = $vid;
                                    $port->save();
                                }
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
                    Log::info('IngestMikrotikTopologyAction: skipping local bridge host entry', ['device_id' => $device->id]);
                    continue;
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
                                if ($scanAgentId <= 0 || empty($device->customer_id)) {
                                    Log::warning('IngestMikrotikTopologyAction: skip DiscoveryEvent (missing scan_agent_id or customer_id)', [
                                        'device_id' => $device->id,
                                        'scan_agent_id' => $device->scan_agent_id,
                                        'customer_id' => $device->customer_id,
                                        'ip_addr' => $addr,
                                    ]);
                                    continue;
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

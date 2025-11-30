<?php

declare(strict_types=1);

namespace App\Actions\Discovery;

use App\Models\DiscoveryEvent;
use App\Models\IpAddress;
use App\Services\IpConversionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

final class IngestScanResultsAction
{
    /**
     * Execute the ingest flow for a batch of scan results.
     *
     * Currently this will create pending DiscoveryEvent records for each result.
     * Later this method will contain the comparison/diff logic against ip_addresses
     * and create conflict records only when necessary.
     *
     * @param array $results Array of validated result payloads
     * @param int $scanAgentId ID of the authenticated ScanAgent that submitted the payload
     */
    public function execute(array $results, int $scanAgentId): void
    {
        Log::info(sprintf('IngestScanResultsAction started — agent=%d, items=%d', $scanAgentId, count($results)));

        $converter = app(IpConversionService::class);

        foreach ($results as $r) {
            Log::info('IngestScanResultsAction: processing result', ['agent' => $scanAgentId, 'ip' => $r['ip_addr'] ?? null]);
            try {
                // Normalize and convert incoming IP to decimal for lookup
                $ipDecimal = null;
                try {
                    $ipDecimal = $converter->toDecimal($r['ip_addr']);
                    Log::info('IngestScanResultsAction: ip conversion successful', ['ip' => $r['ip_addr'] ?? null, 'decimal' => $ipDecimal]);
                } catch (\Throwable $e) {
                    Log::warning('IngestScanResultsAction: invalid ip address in result, skipping', ['ip' => $r['ip_addr'] ?? null, 'error' => $e->getMessage()]);
                }

                if (! $ipDecimal) {
                    // fallback: store as discovery event for manual inspection
                    DiscoveryEvent::create([
                        'scan_agent_id' => $scanAgentId,
                        'ip_addr' => $r['ip_addr'] ?? null,
                        'mac_address_current' => $r['mac_address'] ?? null,
                        'hostname_current' => $r['hostname'] ?? null,
                        'status' => DiscoveryEvent::STATUS_PENDING,
                        'conflict_type' => null,
                        'payload_raw' => $r,
                    ]);
                    continue;
                }

                $existing = IpAddress::where('ip_addr', (string) $ipDecimal)->first();

                // If there is an existing ip address record, compare MACs
                if ($existing) {
                    Log::info('IngestScanResultsAction: existing IpAddress found', ['ip_decimal' => (string) $ipDecimal, 'ip_id' => $existing->id]);
                    $incomingMac = isset($r['mac_address']) ? strtolower($r['mac_address']) : null;
                    $storedMac = $existing->mac_address ? strtolower($existing->mac_address) : null;

                    // If we have both MACs and they differ -> stage conflict
                    if ($incomingMac && $storedMac && $incomingMac !== $storedMac) {
                        Log::info('IngestScanResultsAction: MAC mismatch detected', ['ip_decimal' => (string) $ipDecimal, 'stored_mac' => $storedMac, 'incoming_mac' => $incomingMac]);
                        DiscoveryEvent::create([
                            'scan_agent_id' => $scanAgentId,
                            'ip_addr' => (string) $ipDecimal,
                            'mac_address_current' => $incomingMac,
                            'hostname_current' => $r['hostname'] ?? null,
                            'status' => DiscoveryEvent::STATUS_PENDING,
                            'conflict_type' => 'MAC_MISMATCH',
                            'payload_raw' => $r,
                        ]);
                        continue; // do not update the ip record
                    }

                    // If MACs match (or missing), update last_seen only
                    $existing->last_seen = Carbon::now();
                    $existing->save();
                    continue;
                }

                    // No existing ip record — make creating a discovery event idempotent
                    // Look for an existing pending discovery event for this agent + IP
                    $existingEvent = DiscoveryEvent::where('scan_agent_id', $scanAgentId)
                        ->where('ip_addr', (string) $ipDecimal)
                        ->where('status', DiscoveryEvent::STATUS_PENDING)
                        ->first();

                    if ($existingEvent) {
                        // Update fields if they changed so repeated ingest from same agent
                        // doesn't create duplicates
                        $incomingMac = isset($r['mac_address']) ? strtolower($r['mac_address']) : null;
                        $incomingHostname = $r['hostname'] ?? null;
                        $shouldSave = false;

                        $existingMac = $existingEvent->mac_address_current ? strtolower($existingEvent->mac_address_current) : null;
                        if ($incomingMac && $existingMac !== $incomingMac) {
                            $existingEvent->mac_address_current = $incomingMac;
                            $shouldSave = true;
                        }

                        if ($incomingHostname && $existingEvent->hostname_current !== $incomingHostname) {
                            $existingEvent->hostname_current = $incomingHostname;
                            $shouldSave = true;
                        }

                        if ($shouldSave) {
                            $existingEvent->touch();
                            $existingEvent->save();
                            Log::info('IngestScanResultsAction: updated existing pending event', ['scan_agent_id' => $scanAgentId, 'ip_decimal' => (string) $ipDecimal, 'event_id' => $existingEvent->id]);
                        } else {
                            Log::info('IngestScanResultsAction: pending event exists, no changes', ['scan_agent_id' => $scanAgentId, 'ip_decimal' => (string) $ipDecimal, 'event_id' => $existingEvent->id]);
                        }
                    } else {
                        Log::info('IngestScanResultsAction: creating new pending DiscoveryEvent', ['ip_decimal' => (string) $ipDecimal, 'mac' => $r['mac_address'] ?? null]);
                        DiscoveryEvent::create([
                            'scan_agent_id' => $scanAgentId,
                            'ip_addr' => (string) $ipDecimal,
                            'mac_address_current' => $r['mac_address'] ?? null,
                            'hostname_current' => $r['hostname'] ?? null,
                            'status' => DiscoveryEvent::STATUS_PENDING,
                            'conflict_type' => null,
                            'payload_raw' => $r,
                        ]);
                    }
            } catch (\Throwable $e) {
                // Log and continue — do not fail the whole batch because of a single bad row
                Log::warning('IngestScanResultsAction: failed to process result', ['error' => $e->getMessage(), 'result' => $r]);
                continue;
            }
        }
    }
}

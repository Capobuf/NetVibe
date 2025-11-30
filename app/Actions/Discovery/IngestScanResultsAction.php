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
        $converter = app(IpConversionService::class);

        foreach ($results as $r) {
            try {
                // Normalize and convert incoming IP to decimal for lookup
                $ipDecimal = null;
                try {
                    $ipDecimal = $converter->toDecimal($r['ip_addr']);
                } catch (\Throwable $e) {
                    Log::warning('IngestScanResultsAction: invalid ip address in result, skipping', ['ip' => $r['ip_addr'] ?? null]);
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
                    $incomingMac = isset($r['mac_address']) ? strtolower($r['mac_address']) : null;
                    $storedMac = $existing->mac_address ? strtolower($existing->mac_address) : null;

                    // If we have both MACs and they differ -> stage conflict
                    if ($incomingMac && $storedMac && $incomingMac !== $storedMac) {
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

                // No existing ip record — create a discovery event for now
                DiscoveryEvent::create([
                    'scan_agent_id' => $scanAgentId,
                    'ip_addr' => (string) $ipDecimal,
                    'mac_address_current' => $r['mac_address'] ?? null,
                    'hostname_current' => $r['hostname'] ?? null,
                    'status' => DiscoveryEvent::STATUS_PENDING,
                    'conflict_type' => null,
                    'payload_raw' => $r,
                ]);
            } catch (\Throwable $e) {
                // Log and continue — do not fail the whole batch because of a single bad row
                Log::warning('IngestScanResultsAction: failed to process result', ['error' => $e->getMessage(), 'result' => $r]);
                continue;
            }
        }
    }
}

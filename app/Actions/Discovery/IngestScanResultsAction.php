<?php

declare(strict_types=1);

namespace App\Actions\Discovery;

use App\Models\DiscoveryEvent;
use App\Models\IpAddress;
use App\Tenancy\TenantContextStore;
use App\Services\IpConversionService;
use Illuminate\Support\Facades\Log;
use App\Models\Subnet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

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

        /** @var TenantContextStore $tenantStore */
        $tenantStore = app(TenantContextStore::class);
        $customerId = $tenantStore->requireCustomerId();

        // Resolve scan agent and validate that it belongs to the active tenant (fast-fail).
        $agent = \App\Models\ScanAgent::find($scanAgentId);

        if (! $agent) {
            throw new \RuntimeException('ScanAgent not found: ' . $scanAgentId);
        }

        if (! $agent->customer_id || (int) $agent->customer_id !== (int) $customerId) {
            throw new \RuntimeException('ScanAgent customer mismatch or missing customer_id.');
        }

        // Candidate subnets are strictly tenant-scoped (1 agent per customer).
        $candidateSubnets = Subnet::withoutTenantScope(fn () => Subnet::query()
            ->where('customer_id', $customerId)
            ->where('discover_subnet', true)
            ->get(['id', 'subnet', 'mask', 'cidr', 'customer_id', 'discover_subnet'])
        );

        // Debug: log candidate subnets so we can see what scope the agent has
        try {
            Log::info('Ingest Debug: Candidate Subnets Count: ' . $candidateSubnets->count());
            foreach ($candidateSubnets as $sub) {
                Log::info(sprintf('Ingest Debug: - Candidate: ID %s, SubnetDec: %s, Mask: %s', $sub->id, $sub->subnet, $sub->mask));
            }
        } catch (\Throwable $e) {
            Log::warning('Ingest Debug: failed to dump candidateSubnets: ' . $e->getMessage());
        }

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
                        'customer_id' => $customerId,
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

                // Debug: show which IP we're processing and its decimal representation to validate conversions
                try {
                    Log::info(sprintf('Ingest Debug: Processing IP %s (Decimal: %s)', $r['ip_addr'] ?? '', (string) $ipDecimal));
                } catch (\Throwable $e) {
                    Log::warning('Ingest Debug: unable to log processing ip: ' . $e->getMessage());
                }

                $existing = IpAddress::where('customer_id', $customerId)->where('ip_addr', (string) $ipDecimal)->first();

                // If there is an existing ip address record, compare MACs
                if ($existing) {
                    Log::info('IngestScanResultsAction: existing IpAddress found', ['ip_decimal' => (string) $ipDecimal, 'ip_id' => $existing->id]);
                    $incomingMac = isset($r['mac_address']) ? strtolower($r['mac_address']) : null;
                    $storedMac = $existing->mac_address ? strtolower($existing->mac_address) : null;

                    // If we have both MACs and they differ -> stage conflict
                    if ($incomingMac && $storedMac && $incomingMac !== $storedMac) {
                        Log::info('IngestScanResultsAction: MAC mismatch detected', ['ip_decimal' => (string) $ipDecimal, 'stored_mac' => $storedMac, 'incoming_mac' => $incomingMac]);

                        // Idempotent: update or create a pending discovery event for this agent+IP
                        $this->upsertPendingDiscoveryEvent(
                            $customerId,
                            $scanAgentId,
                            (string) $ipDecimal,
                            $incomingMac,
                            $r['hostname'] ?? null,
                            'MAC_MISMATCH',
                            $r,
                        );
                            

                        continue; // do not update the ip record
                    }

                    // If MACs match (or missing), update last_seen only
                    $existing->last_seen = Carbon::now();
                    $existing->markSeen($existing->last_seen);
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

                        // SMART RETRY: existing pending event — reattempt auto-import if the subnet is now available
                        $existingType = $existingEvent->conflict_type;
                        $canAttemptImport = $existingEvent->status === DiscoveryEvent::STATUS_PENDING
                            && (
                                is_null($existingType)
                                || $existingType === ''
                                || strtolower((string) $existingType) === 'null'
                                || $existingType === 'ORPHAN_IP'
                            );

                        if ($canAttemptImport) {
                            $foundSubnet = null;
                            $subnetId = null;
                            $customerId = null;

                            foreach ($candidateSubnets as $s) {
                                if (! empty($s->subnet) && isset($s->mask)) {
                                    if ($converter->isIpInSubnet((string) $ipDecimal, (string) $s->subnet, (int) $s->mask)) {
                                        $foundSubnet = $s;
                                        $subnetId = $s->getKey();
                                        $customerId = $s->customer_id;
                                        break;
                                    }
                                }
                            }

                            if ($foundSubnet && $foundSubnet->discover_subnet) {
                                try {
                                    DB::transaction(function () use ($ipDecimal, $subnetId, $customerId, $r) {
                                        // Attempt to find an existing ip with a lock
                                        $existingLocked = IpAddress::where('customer_id', $customerId)->where('ip_addr', (string) $ipDecimal)->lockForUpdate()->first();

                                        if ($existingLocked) {
                                            // Another concurrent transaction already created it — update last_seen and skip
                                            $existingLocked->last_seen = Carbon::now();
                                            $existingLocked->markSeen($existingLocked->last_seen);
                                            $existingLocked->save();
                                            return;
                                        }

                                        $this->createIpAddressFromResult((string) $ipDecimal, $subnetId, $customerId, $r);
                                    });

                                    // Flip the pending event to APPROVED and keep the history
                                    $existingEvent->status = DiscoveryEvent::STATUS_APPROVED;
                                    $existingEvent->conflict_type = null;
                                    $existingEvent->save();

                                    // Keep an approved audit record as well
                                    $this->createApprovedDiscoveryEvent($customerId, $scanAgentId, (string) $ipDecimal, $r);

                                    Log::info('IngestScanResultsAction: pending event auto-imported and approved', ['scan_agent_id' => $scanAgentId, 'ip_decimal' => (string) $ipDecimal, 'event_id' => $existingEvent->id]);
                                } catch (QueryException $qe) {
                                    Log::warning('IngestScanResultsAction: QueryException during pending-event auto-import', ['error' => $qe->getMessage(), 'ip' => (string) $ipDecimal]);

                                    // Explicitly verify whether an IP record actually exists before marking event APPROVED.
                                    // A QueryException may mean a race condition OR a failed insert — do not assume success.
                                    $ipActuallyExists = IpAddress::where('customer_id', $customerId)
                                        ->where('subnet_id', $subnetId)
                                        ->where('ip_addr', (string) $ipDecimal)
                                        ->exists();

                                    if ($ipActuallyExists) {
                                        // Another process created the IP concurrently — record approved history
                                        $existingEvent->status = DiscoveryEvent::STATUS_APPROVED;
                                        $existingEvent->conflict_type = null;
                                        $existingEvent->save();

                                        $this->createApprovedDiscoveryEvent($customerId, $scanAgentId, (string) $ipDecimal, $r);
                                        Log::info('IngestScanResultsAction: pending-event auto-import resolved by concurrent create', ['ip' => (string) $ipDecimal]);
                                    } else {
                                        // Insert failed and IP does not exist — keep existing event PENDING for manual review
                                        Log::error('IngestScanResultsAction: pending-event auto-import failed and ip not present after QueryException — leaving event PENDING', ['error' => $qe->getMessage(), 'ip' => (string) $ipDecimal, 'event_id' => $existingEvent->id]);
                                    }
                                }
                                // whether succeeded or failed, continue to next result
                                continue;
                            }
                        }
                    } else {
                        // No existing ip record — attempt to auto-import into ip_addresses
                        Log::info('IngestScanResultsAction: no existing ip record; attempting auto-import', ['ip_decimal' => (string) $ipDecimal]);

                                // Attempt to find the subnet that contains this IP – but scoped to the agent's tenant
                                $subnetId = null;
                                $customerId = null;
                                $foundSubnet = null;
                                try {
                                    foreach ($candidateSubnets as $s) {
                                        if (! empty($s->subnet) && isset($s->mask)) {
                                            if ($converter->isIpInSubnet((string) $ipDecimal, (string) $s->subnet, (int) $s->mask)) {
                                                $subnetId = $s->getKey();
                                                $customerId = $s->customer_id;
                                                $foundSubnet = $s;
                                                break;
                                            }
                                        }
                                    }
                                } catch (\Throwable $e) {
                                    Log::warning('IngestScanResultsAction: subnet detection failed', ['error' => $e->getMessage(), 'ip' => (string) $ipDecimal]);
                                }

                        // Only auto-import when the IP maps into a subnet which allows discovery
                        if (is_null($foundSubnet) || ! $foundSubnet->discover_subnet) {
                            Log::info('IngestScanResultsAction: auto-import skipped because subnet discovery is disabled or subnet not found', ['ip_decimal' => (string) $ipDecimal, 'subnet_id' => $subnetId]);

                            // Create a pending discovery event for manual review
                            $this->upsertPendingDiscoveryEvent(
                                $customerId,
                                $scanAgentId,
                                (string) $ipDecimal,
                                strtolower($r['mac_address'] ?? null),
                                $r['hostname'] ?? null,
                                'ORPHAN_IP',
                                $r,
                            );

                            continue;
                        }

                        // Use a transaction + lock to avoid races when creating an ip record
                        try {
                            DB::transaction(function () use ($ipDecimal, $subnetId, $customerId, $r, $scanAgentId) {
                                // Attempt to find an existing ip with a lock
                                $existingLocked = IpAddress::where('customer_id', $customerId)->where('ip_addr', (string) $ipDecimal)->lockForUpdate()->first();

                                if ($existingLocked) {
                                    // Another concurrent transaction already created it — update last_seen and skip
                                    $existingLocked->last_seen = Carbon::now();
                                    $existingLocked->markSeen($existingLocked->last_seen);
                                    $existingLocked->save();
                                    return;
                                }

                                $this->createIpAddressFromResult((string) $ipDecimal, $subnetId, $customerId, $r);
                            });

                            // Record approved discovery event for audit/history
                            $this->createApprovedDiscoveryEvent($customerId, $scanAgentId, (string) $ipDecimal, $r);

                            Log::info('IngestScanResultsAction: auto-import completed', ['ip_decimal' => (string) $ipDecimal]);
                        } catch (QueryException $qe) {
                            // Fallback: concurrent create caused a unique/constraint problem or insert failed.
                            Log::warning('IngestScanResultsAction: QueryException during auto-import', ['error' => $qe->getMessage(), 'ip_decimal' => (string) $ipDecimal]);

                            // Check if IP already exists due to a concurrent transaction
                            $ipActuallyExists = IpAddress::where('customer_id', $customerId)
                                ->where('subnet_id', $subnetId)
                                ->where('ip_addr', (string) $ipDecimal)
                                ->exists();

                            if ($ipActuallyExists) {
                                // Another process created the IP — create approved discovery event for history
                                $this->createApprovedDiscoveryEvent($customerId, $scanAgentId, (string) $ipDecimal, $r);
                                Log::info('IngestScanResultsAction: auto-import QueryException resolved by concurrent create', ['ip_decimal' => (string) $ipDecimal]);
                            } else {
                                // Attempt a safe fallback creation, if that fails keep a pending discovery event.
                                try {
                                    $newIp = $this->createIpAddressFromResult((string) $ipDecimal, $subnetId, $customerId, $r);
                                    $this->createApprovedDiscoveryEvent($customerId, $scanAgentId, (string) $ipDecimal, $r);
                                    Log::info('IngestScanResultsAction: auto-import fallback completed', ['ip_decimal' => (string) $ipDecimal]);
                                } catch (\Throwable $e) {
                                    // Final failure — do NOT mark an APPROVED event because no ip exists.
                                    Log::error('IngestScanResultsAction: auto-import failed and fallback create failed — leaving pending', ['error' => $e->getMessage(), 'ip_decimal' => (string) $ipDecimal]);

                                    // Create a pending discovery event so this IP can be investigated manually
                                    $this->upsertPendingDiscoveryEvent(
                                        $customerId,
                                        $scanAgentId,
                                        (string) $ipDecimal,
                                        strtolower($r['mac_address'] ?? null),
                                        $r['hostname'] ?? null,
                                        'AUTO_IMPORT_FAILED',
                                        $r,
                                    );
                                }
                            }
                        }
                    }
            } catch (\Throwable $e) {
                // Log and continue — do not fail the whole batch because of a single bad row
                Log::warning('IngestScanResultsAction: failed to process result', ['error' => $e->getMessage(), 'result' => $r]);
                continue;
            }
        }
    }

    private function upsertPendingDiscoveryEvent(int $customerId, int $scanAgentId, string $ipDecimal, ?string $incomingMac, ?string $hostname, ?string $conflictType, array $payloadRaw): void
    {
        $existingEvent = DiscoveryEvent::where('scan_agent_id', $scanAgentId)
            ->where('ip_addr', $ipDecimal)
            ->where('status', DiscoveryEvent::STATUS_PENDING)
            ->first();

        if ($existingEvent) {
            $shouldSave = false;

            $existingMac = $existingEvent->mac_address_current ? strtolower($existingEvent->mac_address_current) : null;
            $incomingMacLower = $incomingMac ? strtolower($incomingMac) : null;
            if ($incomingMacLower && $existingMac !== $incomingMacLower) {
                $existingEvent->mac_address_current = $incomingMacLower;
                $shouldSave = true;
            }

            if ($hostname && $existingEvent->hostname_current !== $hostname) {
                $existingEvent->hostname_current = $hostname;
                $shouldSave = true;
            }

            if ($shouldSave) {
                $existingEvent->payload_raw = $payloadRaw;
                $existingEvent->touch();
                $existingEvent->save();
                Log::info('IngestScanResultsAction: updated existing pending event', ['event_id' => $existingEvent->id]);
            } else {
                Log::info('IngestScanResultsAction: pending event exists, no changes', ['event_id' => $existingEvent->id]);
            }

            return;
        }

        DiscoveryEvent::create([
            'customer_id' => $customerId,
            'scan_agent_id' => $scanAgentId,
            'ip_addr' => $ipDecimal,
            'mac_address_current' => $incomingMac,
            'hostname_current' => $hostname,
            'status' => DiscoveryEvent::STATUS_PENDING,
            'conflict_type' => $conflictType,
            'payload_raw' => $payloadRaw,
        ]);
    }

    private function createIpAddressFromResult(string $ipDecimal, ?int $subnetId, ?int $customerId, array $r): IpAddress
    {
        if ($subnetId === null || $customerId === null) {
            throw new \RuntimeException('Cannot create IpAddress without subnet_id and customer_id.');
        }

        $ipAttrs = ['ip_addr' => $ipDecimal];
        $ipValues = [
            // ensure we use the concrete subnet id
            'subnet_id' => $subnetId,
            // keep the decimal representation for ip_addr; ip_str handled by model setter
            'mac_address' => $r['mac_address'] ?? null,
            'hostname' => $r['hostname'] ?? null,
            // state is an integer enum in the DB; '2' is the chosen value for Used/Online
            'state' => 2,
            // use the provided customer id (belongs to subnet)
            'customer_id' => $customerId,
            // Use the application enum for ip type
            'type' => \App\Enums\IpAddressType::Host,
            'last_seen' => Carbon::now(),
            'description' => $r['description'] ?? 'Discovered by Agent',
        ];

        Log::info('IngestScanResultsAction: creating ip address', $ipValues);

        // Wrap actual create in try/catch to capture SQL/DB errors (FKs, NOT NULL, etc.)
        try {
            // Prefer create to make errors obvious; the firstOrCreate path was used previously as a fallback,
            // but we keep firstOrCreate semantics in the caller (we may call from a transaction where caller handles concurrency)
            if (IpAddress::where('customer_id', $customerId)->where('ip_addr', $ipDecimal)->exists()) {
                return IpAddress::where('customer_id', $customerId)->where('ip_addr', $ipDecimal)->first();
            }

            $payload = array_merge($ipAttrs, $ipValues);

            // Use create and let exceptions bubble for visibility
            $ip = IpAddress::create($payload);

            Log::info('IngestScanResultsAction: SUCCESS created ip address', ['id' => $ip->id, 'ip' => $ipDecimal]);

            return $ip;
        } catch (\Throwable $e) {
            // Critical error on insert: log a detailed message with payload so we can reproduce and fix schema/constraints
            Log::error('CRITICAL SQL ERROR during IP Create: ' . $e->getMessage());
            try {
                Log::error('CRITICAL SQL ERROR - Payload attempted: ' . json_encode($ipValues));
            } catch (\Throwable $_) {
                Log::error('CRITICAL SQL ERROR - Payload attempted (json encode failed)');
            }

            // Rethrow so higher-level code can handle fallback paths (firstOrCreate or pending-events)
            throw $e;
        }
    }

    private function createApprovedDiscoveryEvent(int $customerId, int $scanAgentId, string $ipDecimal, array $r): void
    {
        DiscoveryEvent::create([
            'customer_id' => $customerId,
            'scan_agent_id' => $scanAgentId,
            'ip_addr' => $ipDecimal,
            'mac_address_current' => $r['mac_address'] ?? null,
            'hostname_current' => $r['hostname'] ?? null,
            'status' => DiscoveryEvent::STATUS_APPROVED,
            'conflict_type' => null,
            'payload_raw' => $r,
        ]);
    }
}

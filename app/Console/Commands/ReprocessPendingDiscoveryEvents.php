<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DiscoveryEvent;
use App\Models\ScanAgent;
use App\Models\IpAddress;
use App\Actions\Discovery\IngestScanResultsAction;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextStore;

final class ReprocessPendingDiscoveryEvents extends Command
{
    protected $signature = 'discovery:reprocess-pending {--limit=100}';
    protected $description = 'Reprocess PENDING DiscoveryEvent rows and attempt auto-import using the ingest flow';

    public function handle(IngestScanResultsAction $action): int
    {
        /** @var TenantContextStore $tenantStore */
        $tenantStore = app(TenantContextStore::class);

        $limit = (int) $this->option('limit');

        $this->info(sprintf('Scanning for up to %d pending discovery events...', $limit));

        $events = DiscoveryEvent::where('status', DiscoveryEvent::STATUS_PENDING)
            ->orderBy('updated_at', 'asc')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            $this->info('No pending discovery events found.');
            return 0;
        }

        // Group by agent id so we can reuse the ingest path per agent
        $byAgent = $events->groupBy('scan_agent_id');

        foreach ($byAgent as $agentId => $agentEvents) {
            if (empty($agentId)) {
                $this->error(sprintf('Skipping %d events with NULL scan_agent_id (cannot safely reprocess).', $agentEvents->count()));
                continue;
            }

            $agent = ScanAgent::find((int) $agentId);
            if (! $agent) {
                $this->error(sprintf('Skipping %d events for missing agent id=%s', $agentEvents->count(), $agentId));
                continue;
            }

            if (empty($agent->customer_id)) {
                $this->error(sprintf('Skipping %d events for agent id=%s because scan_agents.customer_id is NULL', $agentEvents->count(), $agentId));
                continue;
            }

            // Fast-fail guard: events must match the agent's tenant.
            $mismatch = $agentEvents->first(function ($e) use ($agent) {
                return (int) $e->customer_id !== (int) $agent->customer_id;
            });
            if ($mismatch) {
                $this->error(sprintf('Skipping agent id=%s: found discovery_event id=%d with mismatched customer_id.', $agentId, $mismatch->id));
                continue;
            }

            // Set tenant context for the duration of this agent batch so ingest uses the same rules as the API.
            $tenantStore->set(new TenantContext((int) $agent->customer_id, null, 'cli', 'discovery:reprocess-pending'));
            $this->info(sprintf('Reprocessing %d events for agent=%s', $agentEvents->count(), $agentId));

            try {
                foreach ($agentEvents as $ev) {
                    $payload = is_array($ev->payload_raw) ? $ev->payload_raw : (array) $ev->payload_raw;

                    try {
                        // Call the normal ingest path with a single result; it will attempt smart-retry / auto-import
                        $action->execute([$payload], (int) $ev->scan_agent_id);

                        // If the IP now exists, mark the original pending event as approved.
                        $ipExists = IpAddress::where('customer_id', (int) $agent->customer_id)
                            ->where('ip_addr', (string) $ev->ip_addr)
                            ->exists();

                        if ($ipExists && $ev->status === DiscoveryEvent::STATUS_PENDING) {
                            $ev->status = DiscoveryEvent::STATUS_APPROVED;
                            $ev->save();
                        }
                    } catch (\Throwable $e) {
                        // Log and continue; avoid failing the entire batch
                        $this->error(sprintf('Failed to reprocess event id=%d ip=%s error=%s', $ev->id, $ev->ip_addr, $e->getMessage()));
                        continue;
                    }

                    $this->info(sprintf('Processed event id=%d ip=%s', $ev->id, $ev->ip_addr));
                }
            } finally {
                $tenantStore->clear();
            }
        }

        $this->info('Done.');
        return 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DiscoveryEvent;
use App\Actions\Discovery\IngestScanResultsAction;

final class ReprocessPendingDiscoveryEvents extends Command
{
    protected $signature = 'discovery:reprocess-pending {--limit=100}';
    protected $description = 'Reprocess PENDING DiscoveryEvent rows and attempt auto-import using the ingest flow';

    public function handle(IngestScanResultsAction $action): int
    {
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
            $this->info(sprintf('Reprocessing %d events for agent=%s', $agentEvents->count(), $agentId));

            foreach ($agentEvents as $ev) {
                $payload = is_array($ev->payload_raw) ? $ev->payload_raw : (array) $ev->payload_raw;

                try {
                    // Call the normal ingest path with a single result; it will attempt smart-retry / auto-import
                    $action->execute([$payload], (int) $ev->scan_agent_id);
                } catch (\Throwable $e) {
                    // Log and continue; avoid failing the entire batch
                    $this->error(sprintf('Failed to reprocess event id=%d ip=%s error=%s', $ev->id, $ev->ip_addr, $e->getMessage()));
                    continue;
                }

                $this->info(sprintf('Processed event id=%d ip=%s', $ev->id, $ev->ip_addr));
            }
        }

        $this->info('Done.');
        return 0;
    }
}

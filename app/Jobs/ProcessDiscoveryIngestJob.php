<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Discovery\IngestScanResultsAction;
use App\Models\ScanAgent;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessDiscoveryIngestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public array $results, public int $scanAgentId)
    {
    }

    public function handle(IngestScanResultsAction $action): void
    {
        $store = app(TenantContextStore::class);

        // Fast-fail: ingestion must always be bound to a specific customer.
        $agent = ScanAgent::query()->findOrFail($this->scanAgentId);

        if (blank($agent->customer_id)) {
            throw new \RuntimeException('ScanAgent must be bound to a customer (customer_id is required) for ingestion jobs.');
        }

        $store->set(new TenantContext(
            customerId: (int) $agent->customer_id,
            locationId: null,
            actorType: 'queue',
            actorId: 'scan_agent:' . (string) $agent->getKey(),
        ));

        try {
            // Delegate the heavy lifting to the action class so the job remains small and testable.
            $action->execute($this->results, $this->scanAgentId);
        } finally {
            // Important for long-running queue workers processing multiple jobs in the same process.
            $store->clear();
        }
    }
}

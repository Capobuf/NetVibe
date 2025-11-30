<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Discovery\IngestScanResultsAction;
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
        // Delegate the heavy lifting to the action class so the job remains small and testable.
        $action->execute($this->results, $this->scanAgentId);
    }
}

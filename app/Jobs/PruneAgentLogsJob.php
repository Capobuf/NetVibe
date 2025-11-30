<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AgentLog;
use App\Settings\DiscoverySettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneAgentLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle(): void
    {
        $settings = app(DiscoverySettings::class);
        $days = $settings->prune_logs_days;

        $deleted = AgentLog::where('created_at', '<', now()->subDays($days))->delete();

        \Log::info("Pruned {$deleted} agent logs older than {$days} days");
    }
}

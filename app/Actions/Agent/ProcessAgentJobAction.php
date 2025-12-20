<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Models\AgentJob;
use Illuminate\Support\Facades\Log;

class ProcessAgentJobAction
{
    public function execute(AgentJob $job): void
    {
        // Placeholder: future processing logic should live here.
        Log::info('ProcessAgentJobAction: job processed', ['job_id' => $job->id, 'status' => $job->status]);
    }
}

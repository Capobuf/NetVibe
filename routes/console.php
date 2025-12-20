<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;

Schedule::command('inspire')->hourly();

// Prune old agent logs daily
Schedule::job(new \App\Jobs\PruneAgentLogsJob)->daily();

// Apply soft-retire lifecycle policy daily (stale/retired based on last_seen)
Schedule::job(new \App\Jobs\ApplySoftRetirePolicyJob)->daily();

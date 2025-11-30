<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;

Schedule::command('inspire')->hourly();

// Prune old agent logs daily
Schedule::job(new \App\Jobs\PruneAgentLogsJob)->daily();

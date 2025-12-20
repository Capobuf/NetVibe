<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use App\Models\ScanAgent;
// Assign first customer to Local Worker if present
$agent = ScanAgent::where('name', 'Local Worker')->first();
if (! $agent) {
    echo "AGENT_NOT_FOUND\n";
    exit;
}

$agent->update(['customer_id' => 1]);
echo "UPDATED: agent_id={$agent->id} customer_id=" . $agent->customer_id . PHP_EOL;

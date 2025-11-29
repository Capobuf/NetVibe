<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use App\Models\ScanAgent;
$agent = ScanAgent::where('name', 'Local Worker')->first();
if ($agent) {
    echo 'FOUND: ' . $agent->name . ' id=' . $agent->id . "\n";
    echo json_encode($agent->toArray()) . PHP_EOL;
} else {
    echo "NOTFOUND\n";
}

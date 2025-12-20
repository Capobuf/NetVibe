<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use App\Models\Customer;
$c = Customer::first();
if ($c) {
    echo 'FOUND: ' . $c->name . ' id=' . $c->id . PHP_EOL;
    echo json_encode($c->toArray()) . PHP_EOL;
} else {
    echo "NO_CUSTOMERS\n";
}

<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$subnet = \App\Models\Subnet::find(1);
if (!$subnet) {
    echo "No subnet 1\n";
    exit(1);
}

$w = new \App\Filament\Clusters\Ipam\Resources\Subnets\Widgets\IpAddressVisualGrid();
$w->record = $subnet;
$w->mount();

echo 'renderGrid=' . ($w->renderGrid ? 'true' : 'false') . PHP_EOL;
echo 'totalHosts=' . $w->totalHosts . PHP_EOL;
echo 'cellsCount=' . count($w->cells) . PHP_EOL;
if (count($w->cells) > 0) {
    echo "firstCell:\n";
    print_r($w->cells[0]);
}

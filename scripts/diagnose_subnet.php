<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$id = (int)($argv[1] ?? 1);
$subnet = \App\Models\Subnet::find($id);
if (!$subnet) {
    echo json_encode(['found' => false, 'id' => $id]) . PHP_EOL;
    exit(0);
}

echo json_encode([
    'found' => true,
    'id' => $subnet->id,
    'cidr' => $subnet->cidr,
    'mask' => $subnet->mask,
    'subnet' => (string)$subnet->subnet,
    'ip_count' => $subnet->ipAddresses()->count(),
]) . PHP_EOL;

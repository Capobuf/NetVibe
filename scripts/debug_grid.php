<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$subnet = \App\Models\Subnet::find(1);
if (!$subnet) {
    echo "No subnet 1\n";
    exit(0);
}

$service = app(\App\Services\IpConversionService::class);

$mask = (int) $subnet->mask;
$subnetDec = (string) $subnet->subnet;

$subnetAddress = $service->fromDecimal($subnetDec);
if (! $subnetAddress) {
    echo "fromDecimal returned null for {$subnetDec}\n";
}
else {
    echo "subnetAddress: " . $subnetAddress->toString() . "\n";
}

$cidr = $subnetAddress?->toString() . '/' . $mask;
echo "cidr: {$cidr}\n";
$range = \IPLib\Factory::parseRangeString($cidr);
if (! $range) {
    echo "range parse failed\n";
} else {
    echo "range ok\n";
    $start = method_exists($range, 'getStartAddress') ? $range->getStartAddress() : ($range->getStart() ?? null);
    $end = method_exists($range, 'getEndAddress') ? $range->getEndAddress() : ($range->getEnd() ?? null);
    echo "start: " . ($start?->toString() ?? 'NULL') . "\n";
    echo "end: " . ($end?->toString() ?? 'NULL') . "\n";
    $startDec = $service->toDecimal($start->toString());
    $endDec = $service->toDecimal($end->toString());
    echo "startDec: {$startDec}\n";
    echo "endDec: {$endDec}\n";
    $first = bcadd((string)$startDec, '1');
    $last = bcsub((string)$endDec, '1');
    echo "first: {$first}\n";
    echo "last: {$last}\n";
    echo "compare first<=last: " . (bccomp($first, $last) <= 0 ? 'true' : 'false') . "\n";
}

$hosts = $service->calculateUsableHosts($mask);
echo "usableHosts: {$hosts}\n";


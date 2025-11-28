<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\Customer;
use App\Models\Location;

$c = Customer::create(['name' => 'Single-Loc-Customer']);
$loc = Location::create(['customer_id' => $c->id, 'name' => 'Only Location']);
$ids = Location::where('customer_id', $c->id)->pluck('id');

echo 'count: ' . $ids->count() . "\n";
echo 'first: ' . ($ids->first() ?? 'null') . "\n";

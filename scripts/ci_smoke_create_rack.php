<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Minimal smoke test script that creates a Customer, Location and Rack to
// validate the new migration columns and FK in a running app environment.

use App\Models\Customer;
use App\Models\Location;
use App\Models\Rack;

$c = Customer::create(['name' => 'CI Test Customer']);
$loc = Location::create(['customer_id' => $c->id, 'name' => 'CI Test Location']);
$rack = Rack::create([
    'name' => 'CI-Rack-1',
    'location_id' => $loc->id,
    'model' => 'APC NetShelter',
    'description' => 'Created by CI smoke test',
    'customer_id' => $c->id,
]);

echo "CREATED RACK ID: {$rack->id}\n";

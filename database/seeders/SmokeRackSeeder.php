<?php
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SmokeRackSeeder extends Seeder
{
    public function run(): void
    {
        $customer = \App\Models\Customer::create(['name' => 'Smoke Test Customer']);

        $location = \App\Models\Location::create([
            'customer_id' => $customer->id,
            'name' => 'Smoke Test Location',
        ]);

        $rack = \App\Models\Rack::create([
            'name' => 'Smoke-Rack-1',
            'location_id' => $location->id,
            'model' => 'TestModel 9000',
            'description' => 'Smoke test creation',
            'customer_id' => $customer->id,
        ]);

        $this->command->getOutput()->writeln('Created rack id: ' . $rack->id);
    }
}

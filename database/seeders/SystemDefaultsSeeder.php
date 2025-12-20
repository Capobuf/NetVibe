<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
// VRF and L2Domain removed — no models to seed
use App\Models\Customer;

class SystemDefaultsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Ensure a top-level VRF exists for simple installs
        $customer = Customer::firstOrCreate(
            ['name' => 'System'],
            ['contact_email' => null, 'notes' => 'Auto-created system owner']
        );

        // VRF and L2Domain concepts removed — we only ensure the 'System' customer exists
    }
}

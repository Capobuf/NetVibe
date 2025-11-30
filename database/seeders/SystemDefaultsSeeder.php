<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Vrf;
use App\Models\L2Domain;
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

        Vrf::firstOrCreate(
            ['name' => 'Default'],
            ['description' => 'System default VRF', 'customer_id' => $customer->getKey()]
        );

        // Ensure an L2 Domain exists so VLANs can default sensibly
        L2Domain::firstOrCreate(
            ['name' => 'Default'],
            ['description' => 'System default L2 domain']
        );
    }
}

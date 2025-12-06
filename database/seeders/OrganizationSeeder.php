<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;
use App\Models\Location;
// Vrf removed — no longer used in seeders

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            ['name' => 'Acme Corp', 'contact_email' => 'acme@example.com', 'notes' => 'Cliente Acme'],
            ['name' => 'Globex', 'contact_email' => 'globex@example.com', 'notes' => 'Cliente Globex'],
            ['name' => 'Soylent Corp', 'contact_email' => 'soylent@example.com', 'notes' => 'Cliente Soylent'],
        ];

        foreach ($customers as $c) {
            $customer = Customer::create($c);
            // Locations
            Location::create([
                'customer_id' => $customer->id,
                'name' => $customer->name . ' HQ',
                'address' => 'Via Principale 1, Città',
            ]);
            Location::create([
                'customer_id' => $customer->id,
                'name' => $customer->name . ' Branch',
                'address' => 'Via Secondaria 2, Città',
            ]);
            // VRF concept removed — nothing to seed here
        }
    }
}

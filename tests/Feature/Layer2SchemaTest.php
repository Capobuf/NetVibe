<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Subnet;
use App\Models\Vlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Layer2SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_layer2_tables_and_columns_exist(): void
    {
        // L2 domains deprecated — ensure VLAN and customer columns exist
        $this->assertTrue(Schema::hasTable('vlans'));
        $this->assertTrue(Schema::hasColumn('subnets', 'vlan_id'));
        $this->assertTrue(Schema::hasColumn('subnets', 'customer_id'));
    }

    public function test_relations_between_l2_domain_vlan_and_subnet(): void
    {
        $customer = Customer::factory()->create();

        $vlan = Vlan::create([
            'customer_id' => $customer->id,
            'number' => 200,
            'name' => 'vlan200',
        ]);

        $subnet = Subnet::create([
            'customer_id' => $customer->id,
            'cidr' => '192.0.2.0/24',
            'vlan_id' => $vlan->id,
            'name' => 'subnet-cidr',
        ]);

        $this->assertSame($vlan->id, $subnet->vlan->id);
        $this->assertCount(1, $customer->vlans);
        $this->assertCount(1, $customer->subnets);
    }
}

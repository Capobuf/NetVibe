<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\L2Domain;
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
        $this->assertTrue(Schema::hasTable('l2_domains'));
        $this->assertTrue(Schema::hasTable('vlans'));
        $this->assertTrue(Schema::hasColumn('subnets', 'vlan_id'));
    }

    public function test_relations_between_l2_domain_vlan_and_subnet(): void
    {
        $l2 = L2Domain::create(['name' => 'test-l2', 'description' => 'desc']);

        $vlan = Vlan::create([
            'l2_domain_id' => $l2->id,
            'number' => 200,
            'name' => 'vlan200',
        ]);

        $vrf = \App\Models\Vrf::factory()->create();

        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
            'cidr' => '192.0.2.0/24',
            'vlan_id' => $vlan->id,
            'name' => 'subnet-cidr',
        ]);

        $this->assertSame($vlan->id, $subnet->vlan->id);
        $this->assertCount(1, $l2->vlans);
    }
}

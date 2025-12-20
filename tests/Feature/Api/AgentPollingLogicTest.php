<?php

declare(strict_types=1);

use App\Models\ScanAgent;
use App\Models\Subnet;
use App\Models\Customer;
use Laravel\Sanctum\Sanctum;

// No VRF required after tenancy migration — SubnetFactory creates its own Customer

it('returns subnets directly assigned to the agent', function () {
    $agent = ScanAgent::factory()->create();
    $subnet = Subnet::factory()->create([
        'scan_agent_id' => $agent->id,
        'discover_subnet' => true,
    ]);
    
    Sanctum::actingAs($agent, ['ipam:ingest']);
    
    $response = $this->getJson('/api/v1/agent/config');
    
    $response->assertOk()
        ->assertJsonCount(1, 'subnets')
        ->assertJsonFragment(['id' => $subnet->id]);
});

it('returns subnets inherited via customer relationship', function () {
    $customer = Customer::factory()->create();
    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);
    
    // Subnet belonging to customer, WITHOUT direct agent assignment
    $subnet = Subnet::factory()->create([
        'customer_id' => $customer->id,
        'scan_agent_id' => null,
        'discover_subnet' => true,
    ]);
    
    Sanctum::actingAs($agent, ['ipam:ingest']);
    
    $response = $this->getJson('/api/v1/agent/config');
    
    $response->assertOk()
        ->assertJsonCount(1, 'subnets')
        ->assertJsonFragment(['id' => $subnet->id]);
});

it('does not return subnets with discovery disabled', function () {
    $agent = ScanAgent::factory()->create();
    $subnet = Subnet::factory()->create([
        'scan_agent_id' => $agent->id,
        'discover_subnet' => false, // Discovery disabled
    ]);
    
    Sanctum::actingAs($agent, ['ipam:ingest']);
    
    $response = $this->getJson('/api/v1/agent/config');
    
    $response->assertOk()
        ->assertJsonCount(0, 'subnets');
});

it('does not return subnets from different customer', function () {
    $customer1 = Customer::factory()->create();
    $customer2 = Customer::factory()->create();
    
    $agent = ScanAgent::factory()->create(['customer_id' => $customer1->id]);
    
    // Subnet belonging to different customer
    $subnet = Subnet::factory()->create([
        'customer_id' => $customer2->id,
        'scan_agent_id' => null,
        'discover_subnet' => true,
    ]);
    
    Sanctum::actingAs($agent, ['ipam:ingest']);
    
    $response = $this->getJson('/api/v1/agent/config');
    
    $response->assertOk()
        ->assertJsonCount(0, 'subnets');
});

it('returns both directly assigned and customer inherited subnets', function () {
    $customer = Customer::factory()->create();
    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);
    
    // Direct assignment
    $subnet1 = Subnet::factory()->create([
        'scan_agent_id' => $agent->id,
        'discover_subnet' => true,
    ]);
    
    // Customer inheritance
    $subnet2 = Subnet::factory()->create([
        'customer_id' => $customer->id,
        'scan_agent_id' => null,
        'discover_subnet' => true,
    ]);
    
    Sanctum::actingAs($agent, ['ipam:ingest']);
    
    $response = $this->getJson('/api/v1/agent/config');
    
    $response->assertOk()
        ->assertJsonCount(2, 'subnets')
        ->assertJsonFragment(['id' => $subnet1->id])
        ->assertJsonFragment(['id' => $subnet2->id]);
});

it('returns cidr field correctly formatted', function () {
    $agent = ScanAgent::factory()->create();
    $subnet = Subnet::factory()->create([
        'scan_agent_id' => $agent->id,
        'discover_subnet' => true,
        'cidr' => '192.168.1.0/24',
    ]);
    
    Sanctum::actingAs($agent, ['ipam:ingest']);
    
    $response = $this->getJson('/api/v1/agent/config');
    
    $response->assertOk()
        ->assertJsonFragment(['cidr' => '192.168.1.0/24']);
});

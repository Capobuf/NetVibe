<?php

use App\Models\ScanAgent;
use App\Models\Subnet;
use App\Models\Customer;
use App\Models\Vlan;

beforeEach(function () {
    $this->artisan('migrate:fresh');
});

test('polling returns correctly formatted CIDR and vlan_id', function () {
    $customer = Customer::factory()->create();
    $vlan = Vlan::factory()->create(['customer_id' => $customer->id]);
    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);
    
    $subnet = Subnet::factory()->create([
        'vlan_id' => $vlan->id,
        'customer_id' => $customer->id,
        'discover_subnet' => true,
        'subnet' => ip2long('192.168.1.0'),
        'mask' => 24,
    ]);

    $token = $agent->createToken('test-token', ['ipam:ingest'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/agent/config');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'subnets' => [
                '*' => ['id', 'cidr', 'vlan_id', 'description']
            ]
        ]);

    $subnets = $response->json('subnets');
    expect($subnets)->toHaveCount(1);
    expect($subnets[0]['cidr'])->toBe('192.168.1.0/24');
    expect($subnets[0]['vlan_id'])->toBe($vlan->id);
});

test('polling respects customer inheritance', function () {
    $customerA = Customer::factory()->create(['name' => 'Customer A']);
    $vlanA = Vlan::factory()->create(['customer_id' => $customerA->id]);
    $agent = ScanAgent::factory()->create(['customer_id' => $customerA->id]);
    
    $subnet = Subnet::factory()->create([
        'vlan_id' => $vlanA->id,
        'customer_id' => $customerA->id,
        'discover_subnet' => true,
        'scan_agent_id' => null, // No direct assignment
        'subnet' => ip2long('10.0.0.0'),
        'mask' => 24,
    ]);

    $token = $agent->createToken('test-token', ['ipam:ingest'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/agent/config');

    $response->assertStatus(200);
    $subnets = $response->json('subnets');
    expect($subnets)->toHaveCount(1);
    expect($subnets[0]['cidr'])->toBe('10.0.0.0/24');
});

test('polling isolates subnets by customer', function () {
    $customerA = Customer::factory()->create(['name' => 'Customer A']);
    $customerB = Customer::factory()->create(['name' => 'Customer B']);
    $vlanB = Vlan::factory()->create(['customer_id' => $customerB->id]);
    
    $agentA = ScanAgent::factory()->create(['customer_id' => $customerA->id]);
    
    $subnetB = Subnet::factory()->create([
        'vlan_id' => $vlanB->id,
        'customer_id' => $customerB->id,
        'discover_subnet' => true,
        'subnet' => ip2long('172.16.0.0'),
        'mask' => 24,
    ]);

    $token = $agentA->createToken('test-token', ['ipam:ingest'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/agent/config');

    $response->assertStatus(200);
    $subnets = $response->json('subnets');
    expect($subnets)->toHaveCount(0); // Agent A should NOT see Customer B's subnet
});

test('polling excludes subnets with discover_subnet disabled', function () {
    $customer = Customer::factory()->create();
    $vlan = Vlan::factory()->create(['customer_id' => $customer->id]);
    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);
    
    $subnet = Subnet::factory()->create([
        'vlan_id' => $vlan->id,
        'customer_id' => $customer->id,
        'discover_subnet' => false, // Discovery disabled
        'subnet' => ip2long('192.168.2.0'),
        'mask' => 24,
    ]);

    $token = $agent->createToken('test-token', ['ipam:ingest'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/agent/config');

    $response->assertStatus(200);
    $subnets = $response->json('subnets');
    expect($subnets)->toHaveCount(0); // Should not return disabled subnet
});

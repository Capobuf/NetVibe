<?php

use App\Models\ScanAgent;
use App\Models\Subnet;
use App\Models\Customer;
use App\Models\Vlan;
use App\Jobs\ProcessDiscoveryIngestJob;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->artisan('migrate:fresh');
});

test('endpoint rejects invalid ip address', function () {
    $agent = ScanAgent::factory()->create();
    $token = $agent->createToken('test-token', ['ipam:ingest'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/v1/discovery/results', [
        'results' => [
            [
                'ip_addr' => 'not-an-ip', // Invalid IP
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'hostname' => 'test',
            ]
        ]
    ]);

    $response->assertStatus(422);
});

test('endpoint rejects missing results key', function () {
    $agent = ScanAgent::factory()->create();
    $token = $agent->createToken('test-token', ['ipam:ingest'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/v1/discovery/results', [
        'data' => [] // Wrong key
    ]);

    $response->assertStatus(422);
});

test('endpoint rejects unauthenticated requests', function () {
    $response = $this->postJson('/api/v1/discovery/results', [
        'results' => []
    ]);

    $response->assertStatus(401);
});

test('endpoint dispatches job on valid payload', function () {
    Queue::fake();

    $customer = Customer::factory()->create();
    $vlan = Vlan::factory()->create(['customer_id' => $customer->id]);
    $subnet = Subnet::factory()->create([
        'vlan_id' => $vlan->id,
        'customer_id' => $customer->id,
        'subnet' => ip2long('192.168.1.0'),
        'mask' => 24,
    ]);

    $agent = ScanAgent::factory()->create();
    $token = $agent->createToken('test-token', ['ipam:ingest'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/v1/discovery/results', [
        'results' => [
            [
                'ip_addr' => '192.168.1.10',
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'hostname' => 'server1',
            ]
        ]
    ]);

    $response->assertStatus(202);
    Queue::assertPushed(ProcessDiscoveryIngestJob::class);
});

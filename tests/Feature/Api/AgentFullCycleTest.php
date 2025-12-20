<?php

use App\Models\ScanAgent;
use App\Models\AgentLog;
use App\Models\Subnet;
use App\Models\Customer;
use App\Models\Vlan;
use Illuminate\Support\Facades\Queue;
use App\Jobs\ProcessDiscoveryIngestJob;

beforeEach(function () {
    $this->artisan('migrate:fresh');
});

test('full agent cycle simulation', function () {
    Queue::fake();

    // Setup
    $customer = Customer::factory()->create();
    $vlan = Vlan::factory()->create(['customer_id' => $customer->id]);
    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);
    $subnet = Subnet::factory()->create([
        'vlan_id' => $vlan->id,
        'customer_id' => $customer->id,
        'subnet' => ip2long('192.168.1.0'),
        'mask' => 24,
        'discover_subnet' => true,
    ]);

    $token = $agent->createToken('test-token', ['ipam:ingest'])->plainTextToken;

    // Step 1: Config (Pull)
    $responseConfig = $this->withToken($token)->getJson('/api/v1/agent/config');
    
    $responseConfig->assertStatus(200)
        ->assertJsonStructure([
            'log_level',
            'subnets' => [
                '*' => ['id', 'cidr']
            ]
        ]);
    
    expect($responseConfig->json('log_level'))->toBe('ERROR'); // Default from migration
    expect($responseConfig->json('subnets'))->toHaveCount(1);

    // Step 2: Push Results
    $responsePush = $this->withToken($token)->postJson('/api/v1/discovery/results', [
        'results' => [
            [
                'ip_addr' => '192.168.1.50',
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'hostname' => 'test-host',
            ]
        ]
    ]);

    $responsePush->assertStatus(202);
    Queue::assertPushed(ProcessDiscoveryIngestJob::class);

    // Step 3: Push Logs
    $responseLog = $this->withToken($token)->postJson('/api/v1/agent/logs', [
        'level' => 'ERROR',
        'message' => 'Simulation error occurred',
        'context' => ['foo' => 'bar']
    ]);

    $responseLog->assertStatus(201);
    
    $log = AgentLog::where('scan_agent_id', $agent->id)->first();
    expect($log)->not->toBeNull();
    expect($log->message)->toBe('Simulation error occurred');
    expect($log->level)->toBe('ERROR');

    // Step 4: Health Check
    $agent->refresh();
    expect($agent->last_seen)->not->toBeNull();
    // Ensure last_seen is recent (within last minute)
    expect($agent->last_seen->diffInMinutes(now()))->toBeLessThan(1);
});

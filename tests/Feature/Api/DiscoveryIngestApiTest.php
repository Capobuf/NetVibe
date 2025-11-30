<?php

use App\Jobs\ProcessDiscoveryIngestJob;
use App\Models\ScanAgent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 401 when no token is provided', function () {
    $payload = ['results' => [['ip_addr' => '192.168.0.1']]];
    $this->postJson('/api/v1/discovery/results', $payload)->assertUnauthorized();
});

it('dispatches the ProcessDiscoveryIngestJob and returns 202', function () {
    Bus::fake();

    $agent = ScanAgent::factory()->create();
    $token = $agent->createToken('test-token', ['ipam:ingest']);

    $results = [
        ['ip_addr' => '192.168.0.10', 'mac_address' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'host-a'],
    ];

    $response = $this
        ->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
        ->postJson('/api/v1/discovery/results', ['results' => $results]);

    $response->assertStatus(202);

    Bus::assertDispatched(ProcessDiscoveryIngestJob::class, function ($job) use ($results, $agent) {
        // Ensure the job was provided the expected payload
        return $job->scanAgentId === $agent->getKey() && $job->results === $results;
    });
});

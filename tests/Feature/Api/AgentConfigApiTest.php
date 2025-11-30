<?php

use App\Models\ScanAgent;
use App\Models\Subnet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 401 when no token is provided', function () {
    $this->getJson('/api/v1/agent/config')->assertUnauthorized();
});

it('returns only the subnets assigned to the authenticated scan agent', function () {
    // Create two agents and assign some subnets to one of them
    $agent = ScanAgent::factory()->create();
    $otherAgent = ScanAgent::factory()->create();

    $assigned = Subnet::factory()->count(3)->create(['scan_agent_id' => $agent->id]);
    $unassigned = Subnet::factory()->count(2)->create(['scan_agent_id' => $otherAgent->id]);

    // Create a Sanctum token with the required ability
    $token = $agent->createToken('test-token', ['ipam:ingest']);

    $response = $this
        ->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
        ->getJson('/api/v1/agent/config');

    $response->assertOk();

    $payload = $response->json('subnets');

    // Should contain only the assigned subnets
    $returnedIds = array_column($payload, 'id');

    foreach ($assigned as $s) {
        expect(in_array($s->id, $returnedIds))->toBeTrue();
    }

    foreach ($unassigned as $s) {
        expect(in_array($s->id, $returnedIds))->toBeFalse();
    }
});

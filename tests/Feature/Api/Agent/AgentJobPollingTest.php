<?php

use App\Models\ScanAgent;
use App\Models\Device;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns agent_config and discovery_config to the agent', function () {
    $agent = ScanAgent::create(['name' => 'agent-poll']);
    $token = $agent->createToken('t-poll');

    $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
        ->getJson('/api/v1/agent/jobs');

    $response->assertOk();
    $json = $response->json();
    expect(array_key_exists('agent_config', $json))->toBeTrue();
    expect(array_key_exists('discovery_config', $json))->toBeTrue();
});

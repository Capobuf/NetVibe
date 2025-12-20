<?php

use App\Models\ScanAgent;
use App\Models\Customer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns agent_config and discovery_config to the agent', function () {
    $customer = Customer::create(['name' => 'Cust']);
    $agent = ScanAgent::create(['name' => 'agent-poll', 'customer_id' => $customer->id, 'active' => true]);
    $token = $agent->createToken('t-poll');

    $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
        ->getJson('/api/v1/agent/jobs');

    $response->assertOk();
    $json = $response->json();
    expect(array_key_exists('agent_config', $json))->toBeTrue();
    expect(array_key_exists('discovery_config', $json))->toBeTrue();
});

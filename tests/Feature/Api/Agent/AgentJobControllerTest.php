<?php

use App\Models\AgentJob;
use App\Models\Device;
use App\Models\ScanAgent;
use App\Models\CredentialProfile;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns pending jobs for authenticated agent', function () {
    $agent = ScanAgent::create(['name' => 'agent-a']);
    $token = $agent->createToken('test-token');

    // create jobs for this agent
    $device = Device::create(['hostname' => 'dev-a', 'device_model_id' => \App\Models\DeviceModel::create(['brand'=>'X','model'=>'x','type'=>'switch'])->id]);

    $pending = AgentJob::create(['device_id' => $device->id, 'scan_agent_id' => $agent->id, 'type' => 'check_connectivity', 'status' => 'pending', 'payload' => []]);
    $other = AgentJob::create(['device_id' => $device->id, 'scan_agent_id' => $agent->id, 'type' => 'check_connectivity', 'status' => 'completed', 'payload' => []]);

    $resp = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
        ->getJson('/api/v1/agent/jobs');

    $resp->assertOk();
    $json = $resp->json();
    expect($json['jobs'])->toBeArray();
    expect(count($json['jobs']))->toBe(1);
    expect($json['jobs'][0]['id'])->toBe($pending->id);
});

it('decrypts credentials and resolves profile data in payload', function () {
    $agent = ScanAgent::create(['name' => 'agent-b']);
    $token = $agent->createToken('test-token');

    $deviceModel = \App\Models\DeviceModel::create(['brand'=>'Acme','model'=>'X','type'=>'switch']);
    $device = Device::create(['hostname' => 'dev-b', 'device_model_id' => $deviceModel->id]);

    // create credential profile with encrypted fields
    $profile = CredentialProfile::create([
        'name' => 'cred-1',
        'username' => 'admin',
        'auth_password' => 'secret-pass',
        'auth_credentials' => ['username' => 'admin', 'password' => 'secret-pass'],
    ]);

    $device->credential_profile_id = $profile->id;
    $device->save();

    $job = AgentJob::create(['device_id' => $device->id, 'scan_agent_id' => $agent->id, 'type' => 'check_connectivity', 'status' => 'pending', 'payload' => []]);

    $resp = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
        ->getJson('/api/v1/agent/jobs');

    $resp->assertOk();
    $jobs = $resp->json('jobs');
    expect(count($jobs))->toBe(1);

    $payload = $jobs[0]['payload'];
    // We expect the credentials to be resolved into the payload
    expect($payload['credentials']['username'])->toBe('admin');
    expect($payload['credentials']['password'])->toBe('secret-pass');
});

it('fails job on unauthorized result push', function () {
    $agent1 = ScanAgent::create(['name' => 'agent-x']);
    $agent2 = ScanAgent::create(['name' => 'agent-y']);

    $token = $agent1->createToken('t1');

    $deviceModel = \App\Models\DeviceModel::create(['brand'=>'Acme','model'=>'X','type'=>'switch']);
    $device = Device::create(['hostname' => 'dev-c', 'device_model_id' => $deviceModel->id]);

    $job = AgentJob::create(['device_id' => $device->id, 'scan_agent_id' => $agent2->id, 'type' => 'check_connectivity', 'status' => 'pending', 'payload' => []]);

    $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
        ->postJson('/api/v1/agent/jobs/'. $job->id .'/result', ['status' => 'completed', 'output' => '{}'])
        ->assertStatus(403);
});

it('updates job status on completed result push', function () {
    $agent = ScanAgent::create(['name' => 'agent-z']);
    $token = $agent->createToken('t3');

    $deviceModel = \App\Models\DeviceModel::create(['brand'=>'Acme','model'=>'X','type'=>'switch']);
    $device = Device::create(['hostname' => 'dev-d', 'device_model_id' => $deviceModel->id]);

    $job = AgentJob::create(['device_id' => $device->id, 'scan_agent_id' => $agent->id, 'type' => 'check_connectivity', 'status' => 'pending', 'payload' => []]);

    $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
        ->postJson('/api/v1/agent/jobs/'. $job->id .'/result', ['status' => 'completed', 'output' => json_encode(['ok' => true]), 'result' => ['ok' => true]])
        ->assertOk();

    $job->refresh();
    expect($job->status)->toBe('completed');
    expect($job->finished_at)->not->toBeNull();
});

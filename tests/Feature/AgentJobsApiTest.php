<?php

namespace Tests\Feature;

use App\Models\ScanAgent;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\CredentialProfile;
use App\Models\AgentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentJobsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_poll_for_jobs_and_get_credentials()
    {
        $agent = ScanAgent::create(['name' => 'agent-a', 'type' => 'direct']);

        $model = DeviceModel::create(['brand' => 'brand', 'model' => 'm1', 'type' => 'router']);

        $profile = CredentialProfile::create(['name' => 'p1', 'type' => 'ssh', 'username' => 'nobody', 'auth_password' => 'secret', 'auth_credentials' => json_encode(['foo' => 'bar'])]);

        $device = Device::create(['hostname' => 'dev1', 'device_model_id' => $model->id, 'credential_profile_id' => $profile->id]);

        $job = AgentJob::create(['device_id' => $device->id, 'scan_agent_id' => $agent->id, 'type' => 'check_connectivity', 'status' => 'pending', 'payload' => []]);

        $this->actingAs($agent, 'sanctum')
            ->getJson('/api/v1/agent/jobs')
            ->assertOk()
            ->assertJsonStructure(['agent_config', 'jobs'])
            ->assertJsonFragment(['id' => $job->id]);
    }

    public function test_agent_can_submit_job_result()
    {
        $agent = ScanAgent::create(['name' => 'agent-a', 'type' => 'direct']);

        $model = DeviceModel::create(['brand' => 'brand', 'model' => 'm1', 'type' => 'router']);
        $device = Device::create(['hostname' => 'dev1', 'device_model_id' => $model->id]);

        $job = AgentJob::create(['device_id' => $device->id, 'scan_agent_id' => $agent->id, 'type' => 'check_connectivity', 'status' => 'pending', 'payload' => []]);

        $payload = ['status' => 'completed', 'output' => 'ok', 'result' => ['ok' => true]];

        $this->actingAs($agent, 'sanctum')
            ->postJson('/api/v1/agent/jobs/' . $job->id . '/result', $payload)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('agent_jobs', ['id' => $job->id, 'status' => 'completed']);
    }
}

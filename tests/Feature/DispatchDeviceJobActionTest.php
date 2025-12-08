<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceModel;
use App\Actions\Agent\DispatchDeviceJobAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DispatchDeviceJobActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_creates_agent_job()
    {
        $model = DeviceModel::create(['brand' => 'Acme', 'model' => 'X1000', 'type' => 'switch']);

        $device = Device::create(['hostname' => 'dev-1', 'device_model_id' => $model->id, 'auth_username' => 'admin', 'auth_password' => 's3cr3t']);

        $user = \App\Models\User::factory()->create();

        $action = app(DispatchDeviceJobAction::class);
        $job = $action->execute($device, 'check_connectivity', null, $user->id);

        $this->assertIsString($job->id);
        $this->assertDatabaseHas('agent_jobs', ['id' => $job->id, 'device_id' => $device->id, 'type' => 'check_connectivity']);

        // Payload contains resolved credentials (username/password)
        $this->assertNotNull($job->payload['username']);
        $this->assertNotNull($job->payload['password']);
        $this->assertEquals('s3cr3t', $job->payload['password']);
        $this->assertDatabaseHas('agent_jobs', ['initiated_by_user_id' => $user->id]);
    }
}

<?php

declare(strict_types=1);

use App\Actions\Agent\DispatchDeviceJobAction;
use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\ScanAgent;

it('assigns jobs to the customer agent and delivers them via API', function () {
    $customer = Customer::create(['name' => 'Cust A']);
    $agent = ScanAgent::create(['name' => 'Agent A', 'customer_id' => $customer->id, 'active' => true]);

    $model = DeviceModel::create(['brand' => 'Acme', 'model' => 'S1', 'type' => 'switch']);
    $device = Device::create([
        'hostname' => 'dev-tenant',
        'device_model_id' => $model->id,
        'customer_id' => $customer->id,
        'auth_username' => 'admin',
        'auth_password' => 'pass',
    ]);

    $job = app(DispatchDeviceJobAction::class)->execute($device);
    expect($job->scan_agent_id)->toBe($agent->id);

    $token = $agent->createToken('t')->plainTextToken;
    $resp = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/v1/agent/jobs');

    $resp->assertOk();
    $resp->assertJsonFragment(['id' => $job->id]);
});

it('prevents multiple active agents per customer', function () {
    $customer = Customer::create(['name' => 'Cust B']);
    ScanAgent::create(['name' => 'Agent One', 'customer_id' => $customer->id, 'active' => true]);

    $this->expectException(\Illuminate\Validation\ValidationException::class);
    ScanAgent::create(['name' => 'Agent Two', 'customer_id' => $customer->id, 'active' => true]);
});

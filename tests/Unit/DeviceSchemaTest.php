<?php

use App\Models\Device;
use App\Models\DeviceModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has new device defaults and fields present', function () {
    $dm = DeviceModel::create(['brand' => 'Acme', 'model' => 'X', 'type' => 'switch']);

    $device = Device::create(['hostname' => 'dev1', 'device_model_id' => $dm->id]);

    // Defaults should be applied by DB migration
    $this->assertDatabaseHas('devices', ['id' => $device->id, 'is_monitored' => 1, 'auth_driver' => 'mikrotik_rest', 'brand' => 'MikroTik']);
});

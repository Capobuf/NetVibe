<?php

use App\Actions\Agent\IngestMikrotikInventoryAction;
use App\Models\Device;
use App\Models\DeviceModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps resource and identity to device fields and creates device model', function () {
    $dm = DeviceModel::create(['brand'=>'Acme','model'=>'Seed','type'=>'switch']);
    $device = Device::create(['hostname' => 'initial', 'device_model_id' => $dm->id]);

    $data = [
        'resource' => ['board-name' => 'RB-CCR', 'version' => '7.15', 'serial-number' => 'ABC123', 'uptime' => '3 days'],
        'identity' => ['name' => 'router-a'],
    ];

    $action = new IngestMikrotikInventoryAction();
    $action->execute($device, $data);

    $device->refresh();
    expect($device->hostname)->toBe('router-a');
    expect($device->serial_number)->toBe('ABC123');
    // os_version should be recorded in auth_extra
    expect($device->auth_extra['os_version'] ?? null)->toBe('7.15');
    expect($device->auth_extra['uptime'] ?? null)->toBe('3 days');
});

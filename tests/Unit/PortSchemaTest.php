<?php

use App\Models\Port;
use App\Models\Device;
use App\Models\DeviceModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores vlan and physical attributes on ports', function () {
    $dm = DeviceModel::create(['brand' => 'Acme', 'model' => 'M', 'type' => 'switch']);
    $device = Device::create(['hostname' => 'dev-p', 'device_model_id' => $dm->id]);

    $record = Port::create(['device_id' => $device->id, 'name' => 'eth0', 'type' => 'rj45', 'vlan_mode' => 'trunk', 'pvid' => 10, 'tagged_vlans' => [10,20], 'mtu' => 1500, 'speed' => '1Gbps', 'duplex' => 'full', 'external_type' => 'ether', 'description' => 'uplink']);

    $this->assertDatabaseHas('ports', ['id' => $record->id, 'pvid' => 10, 'mtu' => 1500, 'external_type' => 'ether']);
    $fresh = Port::find($record->id);
    expect($fresh->tagged_vlans)->toBe([10,20]);
});

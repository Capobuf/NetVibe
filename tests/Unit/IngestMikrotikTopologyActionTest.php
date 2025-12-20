<?php

use App\Actions\Agent\IngestMikrotikTopologyAction;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Port;
use App\Models\IpAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('filters out local self traffic from bridge host', function () {
    $dm = DeviceModel::create(['brand'=>'Acme','model'=>'M','type'=>'switch']);
    $device = Device::create(['hostname' => 'local', 'device_model_id' => $dm->id]);

    $action = new IngestMikrotikTopologyAction();

    $payload = [
        'bridge_hosts' => [
            ['mac-address' => 'aa:aa:aa:aa:aa:aa', 'interface' => 'eth1', 'local' => true],
            ['mac-address' => 'bb:bb:bb:bb:bb:bb', 'interface' => 'eth2', 'local' => false, 'ip-address' => '192.168.1.100'],
        ],
    ];

    $action->execute($device, $payload);

    // local true host should be ignored; non-local should create a discovery event / update ip
    $dec = app(\App\Services\IpConversionService::class)->toDecimal('192.168.1.100');

    $this->assertDatabaseHas('discovery_events', ['ip_addr' => (string) $dec, 'mac_address_current' => 'bb:bb:bb:bb:bb:bb']);
    $this->assertDatabaseMissing('discovery_events', ['mac_address_current' => 'aa:aa:aa:aa:aa:aa']);
});

it('detects and links devices via lldp identity', function () {
    $dm = DeviceModel::create(['brand'=>'Acme','model'=>'M','type'=>'switch']);

    // Create remote device B with a port
    $remote = Device::create(['hostname' => 'device-b', 'device_model_id' => $dm->id]);
    $remotePort = Port::create(['device_id' => $remote->id, 'name' => 'ether1', 'mac_address' => '11:22:33:44:55:66']);

    // Local device A and its port
    $device = Device::create(['hostname' => 'device-a', 'device_model_id' => $dm->id]);
    $localPort = Port::create(['device_id' => $device->id, 'name' => 'ether2']);

    $action = new IngestMikrotikTopologyAction();

    $payload = [
        'ip_neighbors' => [
            ['mac-address' => '11:22:33:44:55:66', 'interface' => 'ether2', 'address' => '10.0.0.2'],
        ],
    ];

    $action->execute($device, $payload);

    $localPort->refresh();
    expect($localPort->connected_to_port_id)->toBe($remotePort->id);
});

it('persists interface VLAN and physical attributes onto ports', function () {
    $dm = DeviceModel::create(['brand'=>'Acme','model'=>'M','type'=>'switch']);
    $device = Device::create(['hostname' => 'int-device', 'device_model_id' => $dm->id]);

    $action = new IngestMikrotikTopologyAction();

    $payload = [
        'interfaces' => [
            [
                'name' => 'eth0',
                'mac-address' => 'aa:bb:cc:11:22:33',
                'mtu' => 1500,
                'speed' => '1Gbps',
                'duplex' => 'full',
                'external_type' => 'ethernet',
                'description' => 'uplink to core',
                'vlan-mode' => 'trunk',
                'pvid' => '10',
                'tagged-vlans' => '10,20,30',
            ],
        ],
    ];

    $action->execute($device, $payload);

    $fresh = Port::where('device_id', $device->id)->where('name', 'eth0')->first();
    expect($fresh)->not->toBeNull();
    expect($fresh->mtu)->toBe(1500);
    expect($fresh->speed)->toBe('1Gbps');
    expect($fresh->duplex)->toBe('full');
    expect($fresh->external_type)->toBe('ethernet');
    expect($fresh->description)->toBe('uplink to core');
    expect($fresh->vlan_mode)->toBe('trunk');
    expect($fresh->pvid)->toBe(10);
    expect($fresh->tagged_vlans)->toBe([10,20,30]);
});

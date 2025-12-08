<?php

use App\Actions\Agent\IngestMikrotikArpAction;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\DeviceModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates new ip address and assigns mac', function () {
    $dm = DeviceModel::create(['brand'=>'Acme','model'=>'M','type'=>'switch']);
    $device = Device::create(['hostname' => 'd1', 'device_model_id' => $dm->id]);

    // Create a subnet covering 192.168.50.0/24 so the action can auto-import IP
    $converter = app(\App\Services\IpConversionService::class);
    $subnetDec = $converter->toDecimal('192.168.50.0');
    \App\Models\Subnet::create(['name' => 's1', 'subnet' => (string) $subnetDec, 'mask' => 24, 'cidr' => '192.168.50.0/24']);

    $action = new IngestMikrotikArpAction();

    $payload = [
        'arp_table' => [
            ['address' => '192.168.50.10', 'mac-address' => 'aa:bb:cc:11:22:33'],
        ],
    ];

    $action->execute($device, $payload);

    $dec = app(\App\Services\IpConversionService::class)->toDecimal('192.168.50.10');

    $this->assertDatabaseHas('ip_addresses', ['ip_addr' => (string) $dec, 'mac_address' => 'aa:bb:cc:11:22:33']);
});

it('respects router wins policy and updates mac', function () {
    $dm = DeviceModel::create(['brand'=>'Acme','model'=>'M','type'=>'switch']);
    $device = Device::create(['hostname' => 'd2', 'device_model_id' => $dm->id]);

    $dec = app(\App\Services\IpConversionService::class)->toDecimal('10.0.0.5');
    // Create a subnet for 10.0.0.0/24
    $subnetDec2 = app(\App\Services\IpConversionService::class)->toDecimal('10.0.0.0');
    $s2 = \App\Models\Subnet::create(['name' => 's2', 'subnet' => (string) $subnetDec2, 'mask' => 24, 'cidr' => '10.0.0.0/24']);

    $existing = IpAddress::create(['ip_addr' => (string) $dec, 'mac_address' => '00:aa:00:aa:00:aa', 'type' => \App\Enums\IpAddressType::Host, 'last_seen' => now(), 'subnet_id' => $s2->id, 'state' => 2]);


    $action = new IngestMikrotikArpAction();

    $payload = [
        'arp_table' => [
            ['address' => '10.0.0.5', 'mac-address' => '11:22:33:44:55:66'],
        ],
    ];

    $action->execute($device, $payload);

    $existing->refresh();
    expect($existing->mac_address)->toBe('11:22:33:44:55:66');
});

it('handles dhcp lease enrichment', function () {
    $dm = DeviceModel::create(['brand'=>'Acme','model'=>'M','type'=>'switch']);
    $device = Device::create(['hostname' => 'd3', 'device_model_id' => $dm->id]);

    $action = new IngestMikrotikArpAction();

    $payload = [
        'dhcp_leases' => [
            ['address' => '172.16.1.20', 'mac-address' => '22:22:22:22:22:22', 'host-name' => 'host-a', 'dynamic' => false, 'comment' => 'reserved'],
        ],
    ];


    // Ensure a matching subnet exists first for DHCP auto-import
    $subnetDec3 = app(\App\Services\IpConversionService::class)->toDecimal('172.16.1.0');
    \App\Models\Subnet::create(['name' => 's3', 'subnet' => (string) $subnetDec3, 'mask' => 24, 'cidr' => '172.16.1.0/24']);

    $action->execute($device, $payload);

    $dec = app(\App\Services\IpConversionService::class)->toDecimal('172.16.1.20');

    $this->assertDatabaseHas('ip_addresses', ['ip_addr' => (string) $dec, 'mac_address' => '22:22:22:22:22:22', 'hostname' => 'host-a', 'type' => \App\Enums\IpAddressType::Reserved]);
});

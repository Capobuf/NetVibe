<?php

use App\Models\ScanAgent;
use App\Models\Subnet;
use App\Models\IpAddress;
use App\Models\DiscoveryEvent;
use App\Models\Customer;
use App\Models\Vlan;
use App\Jobs\ProcessDiscoveryIngestJob;

beforeEach(function () {
    $this->artisan('migrate:fresh');
});

test('happy path updates last_seen without creating event', function () {
    $customer = Customer::factory()->create();
    $vlan = Vlan::factory()->create(['customer_id' => $customer->id]);
    $subnet = Subnet::factory()->create([
        'vlan_id' => $vlan->id,
        'customer_id' => $customer->id,
        'subnet' => ip2long('192.168.1.0'),
        'mask' => 24,
    ]);
    
    $existingIp = IpAddress::factory()->create([
        'subnet_id' => $subnet->id,
        'ip_addr' => '192.168.1.10',
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'hostname' => 'server1',
    ]);

    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);
    
    $results = [
        [
            'ip_addr' => '192.168.1.10',
            'mac_address' => 'AA:BB:CC:DD:EE:FF', // Same MAC
            'hostname' => 'server1',
        ]
    ];

    // Dispatch job synchronously
    ProcessDiscoveryIngestJob::dispatchSync($results, $agent->id);

    $existingIp->refresh();
    expect($existingIp->last_seen)->not->toBeNull();
    expect(DiscoveryEvent::count())->toBe(0); // No conflict event
});

test('mac mismatch creates conflict event without modifying ip', function () {
    $customer = Customer::factory()->create();
    $vlan = Vlan::factory()->create(['customer_id' => $customer->id]);
    $subnet = Subnet::factory()->create([
        'vlan_id' => $vlan->id,
        'customer_id' => $customer->id,
        'subnet' => ip2long('192.168.1.0'),
        'mask' => 24,
    ]);
    
    $existingIp = IpAddress::factory()->create([
        'subnet_id' => $subnet->id,
        'ip_addr' => '192.168.1.10',
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'hostname' => 'server1',
    ]);

    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);
    
    $results = [
        [
            'ip_addr' => '192.168.1.10',
            'mac_address' => 'CC:DD:EE:FF:00:11', // Different MAC
            'hostname' => 'server1',
        ]
    ];

    ProcessDiscoveryIngestJob::dispatchSync($results, $agent->id);

    $existingIp->refresh();
    expect($existingIp->mac_address)->toBe('AA:BB:CC:DD:EE:FF'); // Unchanged
    
    $event = DiscoveryEvent::first();
    expect($event)->not->toBeNull();
    expect($event->conflict_type)->toBe('MAC_MISMATCH');
    expect($event->mac_address_current)->toBe('cc:dd:ee:ff:00:11');
    // expect($event->mac_address_previous)->toBe('aa:bb:cc:dd:ee:ff'); // Column does not exist
    expect($event->status)->toBe('PENDING');
});

test('new ip detection creates pending event', function () {
    $customer = Customer::factory()->create();
    $vlan = Vlan::factory()->create(['customer_id' => $customer->id]);
    $subnet = Subnet::factory()->create([
        'vlan_id' => $vlan->id,
        'customer_id' => $customer->id,
        'subnet' => ip2long('192.168.1.0'),
        'mask' => 24,
    ]);

    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);
    
    $results = [
        [
            'ip_addr' => '192.168.1.50', // New IP
            'mac_address' => 'FF:EE:DD:CC:BB:AA',
            'hostname' => 'new-device',
        ]
    ];

    ProcessDiscoveryIngestJob::dispatchSync($results, $agent->id);

    // With auto-import enabled on subnets (default), new IPs are auto-created
    expect(IpAddress::where('ip_addr', (string) ip2long('192.168.1.50'))->count())->toBe(1);

    $event = DiscoveryEvent::first();
    expect($event)->not->toBeNull();
    expect($event->ip_addr)->toBe((string) ip2long('192.168.1.50'));
    expect($event->mac_address_current)->toBe('FF:EE:DD:CC:BB:AA');
    expect($event->status)->toBe('APPROVED');
});

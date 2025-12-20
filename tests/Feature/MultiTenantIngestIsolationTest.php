<?php

use App\Jobs\ProcessDiscoveryIngestJob;
use App\Models\Customer;
use App\Models\DiscoveryEvent;
use App\Models\IpAddress;
use App\Models\ScanAgent;
use App\Models\Subnet;
use App\Services\IpConversionService;

test('ingest is isolated per customer even when IPs overlap', function () {
    $converter = app(IpConversionService::class);

    $customerA = Customer::factory()->create();
    $agentA = ScanAgent::factory()->create(['customer_id' => $customerA->id]);

    $subnetA = Subnet::factory()->create([
        'customer_id' => $customerA->id,
        'scan_agent_id' => $agentA->id,
        'cidr' => '192.168.0.0/24',
        'discover_subnet' => true,
    ]);

    $ipStr = '192.168.0.10';
    $ipDec = (string) $converter->toDecimal($ipStr);

    $oldA = now()->subDays(2);
    $ipA = IpAddress::create([
        'customer_id' => $customerA->id,
        'subnet_id' => $subnetA->id,
        'ip_addr' => $ipDec,
        'mac_address' => 'aa:aa:aa:aa:aa:aa',
        'hostname' => 'host-a',
        'last_seen' => $oldA,
    ]);

    $customerB = Customer::factory()->create();
    $agentB = ScanAgent::factory()->create(['customer_id' => $customerB->id]);

    $subnetB = Subnet::factory()->create([
        'customer_id' => $customerB->id,
        'scan_agent_id' => $agentB->id,
        'cidr' => '192.168.0.0/24',
        'discover_subnet' => true,
    ]);

    $oldB = now()->subDays(2);
    $ipB = IpAddress::create([
        'customer_id' => $customerB->id,
        'subnet_id' => $subnetB->id,
        'ip_addr' => $ipDec,
        'mac_address' => 'bb:bb:bb:bb:bb:bb',
        'hostname' => 'host-b',
        'last_seen' => $oldB,
    ]);

    $results = [
        ['ip_addr' => $ipStr, 'mac_address' => 'aa:aa:aa:aa:aa:aa', 'hostname' => 'host-a'],
    ];

    ProcessDiscoveryIngestJob::dispatchSync($results, $agentA->id);

    $ipA->refresh();
    $ipB->refresh();

    expect($ipA->last_seen->greaterThan($oldA))->toBeTrue();
    expect($ipB->last_seen->timestamp)->toBe($oldB->timestamp);

    $customerBEvents = DiscoveryEvent::withoutTenantScope(
        fn () => DiscoveryEvent::where('customer_id', $customerB->id)->count()
    );
    expect($customerBEvents)->toBe(0);
});

test('ingest conflict events are recorded on the correct customer', function () {
    $converter = app(IpConversionService::class);

    $customer = Customer::factory()->create();
    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);

    $subnet = Subnet::factory()->create([
        'customer_id' => $customer->id,
        'scan_agent_id' => $agent->id,
        'cidr' => '10.10.0.0/24',
        'discover_subnet' => true,
    ]);

    $ipStr = '10.10.0.10';
    $ipDec = (string) $converter->toDecimal($ipStr);

    IpAddress::create([
        'customer_id' => $customer->id,
        'subnet_id' => $subnet->id,
        'ip_addr' => $ipDec,
        'mac_address' => '11:22:33:44:55:66',
        'hostname' => 'existing',
        'last_seen' => now()->subDay(),
    ]);

    $results = [
        ['ip_addr' => $ipStr, 'mac_address' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'changed'],
    ];

    ProcessDiscoveryIngestJob::dispatchSync($results, $agent->id);

    $event = DiscoveryEvent::withoutTenantScope(fn () => DiscoveryEvent::first());
    expect($event)->not->toBeNull();
    expect((int) $event->customer_id)->toBe((int) $customer->id);
    expect($event->conflict_type)->toBe('MAC_MISMATCH');
});

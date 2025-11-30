<?php

use App\Actions\Discovery\IngestScanResultsAction;
use App\Models\IpAddress;
use App\Models\DiscoveryEvent;
use App\Models\ScanAgent;
use App\Services\IpConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a discovery event with MAC_MISMATCH when incoming MAC differs', function () {
    $agent = ScanAgent::factory()->create();

    $converter = app(IpConversionService::class);
    $ip = '192.168.0.10';
    $ipDecimal = $converter->toDecimal($ip);

    // create a subnet and existing ip address with MAC A
    $subnet = \App\Models\Subnet::factory()->create();
    $existing = IpAddress::create([
        'subnet_id' => $subnet->id,
        'ip_addr' => $ipDecimal,
        'mac_address' => 'aa:aa:aa:aa:aa:aa',
        'last_seen' => now()->subDay(),
    ]);

    $action = new IngestScanResultsAction();

    $results = [
        ['ip_addr' => $ip, 'mac_address' => 'bb:bb:bb:bb:bb:bb', 'hostname' => 'host-b'],
    ];

    $action->execute($results, $agent->id);

    // Assert ip record unchanged (mac still original)
    $existing->refresh();
    expect($existing->mac_address)->toBe('aa:aa:aa:aa:aa:aa');

    // Assert a discovery event was created with conflict_type MAC_MISMATCH
    $events = DiscoveryEvent::where('ip_addr', $ipDecimal)->get();
    expect($events->count())->toBeGreaterThanOrEqual(1);
    $found = $events->first();
    expect($found->conflict_type)->toBe('MAC_MISMATCH');
    expect($found->scan_agent_id)->toBe($agent->id);
});

it('updates last_seen and does not create discovery event when MAC matches', function () {
    $agent = ScanAgent::factory()->create();

    $converter = app(IpConversionService::class);
    $ip = '192.168.0.20';
    $ipDecimal = $converter->toDecimal($ip);

    $initial = now()->subHours(5);

    $subnet = \App\Models\Subnet::factory()->create();
    $existing = IpAddress::create([
        'subnet_id' => $subnet->id,
        'ip_addr' => $ipDecimal,
        'mac_address' => 'cc:cc:cc:cc:cc:cc',
        'last_seen' => $initial,
    ]);

    $action = new IngestScanResultsAction();

    $results = [
        ['ip_addr' => $ip, 'mac_address' => 'cc:cc:cc:cc:cc:cc', 'hostname' => 'host-c'],
    ];

    $action->execute($results, $agent->id);

    $existing->refresh();

    // last_seen should be updated to more recent than initial
    expect($existing->last_seen->greaterThan($initial))->toBeTrue();

    // Ensure no discovery_event created for this ip
    $events = DiscoveryEvent::where('ip_addr', $ipDecimal)->get();
    expect($events->count())->toBe(0);
});

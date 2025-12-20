<?php

use App\Actions\Discovery\IngestScanResultsAction;
use App\Models\IpAddress;
use App\Models\DiscoveryEvent;
use App\Models\ScanAgent;
use App\Models\Customer;
use App\Services\IpConversionService;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    app(TenantContextStore::class)->clear();
});

function setTenant(Customer $customer): void
{
    app(TenantContextStore::class)->set(new TenantContext((int) $customer->id, null, 'test', 'phpunit'));
}

it('creates a discovery event with MAC_MISMATCH when incoming MAC differs', function () {
    $customer = Customer::factory()->create();
    setTenant($customer);
    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);

    $converter = app(IpConversionService::class);
    $ip = '192.168.0.10';
    $ipDecimal = $converter->toDecimal($ip);

    // create a subnet and existing ip address with MAC A
    $subnet = \App\Models\Subnet::factory()->create(['customer_id' => $customer->id, 'cidr' => '192.168.0.0/24']);
    $existing = IpAddress::create([
        'subnet_id' => $subnet->id,
        'ip_addr' => $ipDecimal,
        'mac_address' => 'aa:aa:aa:aa:aa:aa',
        'customer_id' => $customer->id,
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
    $customer = Customer::factory()->create();
    setTenant($customer);
    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);

    $converter = app(IpConversionService::class);
    $ip = '192.168.0.20';
    $ipDecimal = $converter->toDecimal($ip);

    $initial = now()->subHours(5);

    $subnet = \App\Models\Subnet::factory()->create(['customer_id' => $customer->id, 'cidr' => '192.168.0.0/24']);
    $existing = IpAddress::create([
        'subnet_id' => $subnet->id,
        'ip_addr' => $ipDecimal,
        'mac_address' => 'cc:cc:cc:cc:cc:cc',
        'customer_id' => $customer->id,
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

it('does not create duplicate pending events for repeated ingests and updates existing', function () {
    $customer = Customer::factory()->create();
    setTenant($customer);
    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);

    $converter = app(IpConversionService::class);
    $ip = '192.168.0.30';
    $ipDecimal = $converter->toDecimal($ip);

    // Create a subnet and an existing ip so the ingest produces a pending event
    $subnet = \App\Models\Subnet::factory()->create(['customer_id' => $customer->id, 'cidr' => '192.168.0.0/24']);
    $existing = IpAddress::create([
        'subnet_id' => $subnet->id,
        'ip_addr' => $ipDecimal,
        'mac_address' => '11:22:33:44:55:66',
        'customer_id' => $customer->id,
        'last_seen' => now()->subDay(),
    ]);

    $action = new IngestScanResultsAction();

    $results = [
        ['ip_addr' => $ip, 'mac_address' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'host-a'],
    ];

    // First ingest should create a single pending event (MAC mismatch)
    $action->execute($results, $agent->id);

    $events = DiscoveryEvent::where('ip_addr', $ipDecimal)->get();
    expect($events->count())->toBe(1);

    $event = $events->first();
    expect($event->mac_address_current)->toBe('aa:bb:cc:dd:ee:ff');
    expect($event->hostname_current)->toBe('host-a');

    // Second ingest with different mac/hostname should update the existing event
    $results2 = [
        ['ip_addr' => $ip, 'mac_address' => 'dd:ee:ff:00:11:22', 'hostname' => 'host-b'],
    ];

    $action->execute($results2, $agent->id);

    $events2 = DiscoveryEvent::where('ip_addr', $ipDecimal)->get();
    expect($events2->count())->toBe(1);

    $updated = $events2->first();
    expect($updated->mac_address_current)->toBe('dd:ee:ff:00:11:22');
    expect($updated->hostname_current)->toBe('host-b');
});

it('auto-imports a new IP into ip_addresses and records an APPROVED discovery event', function () {
    $customer = Customer::factory()->create();
    setTenant($customer);
    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);

    $converter = app(IpConversionService::class);
    $ip = '192.168.0.40';
    $ipDecimal = $converter->toDecimal($ip);

    // create a subnet covering our IP so it inherits subnet_id/customer
    // assign this subnet to the agent to ensure it is within the agent's scope
    $subnet = \App\Models\Subnet::factory()->create(['cidr' => '192.168.0.0/24', 'customer_id' => $customer->id, 'scan_agent_id' => $agent->id]);

    $action = new IngestScanResultsAction();

    $results = [
        ['ip_addr' => $ip, 'mac_address' => '11:11:11:11:11:11', 'hostname' => 'new-host', 'state' => 'host'],
    ];

    $action->execute($results, $agent->id);

    // ip_addresses should now contain the new ip
    $ipRecord = IpAddress::where('ip_addr', $ipDecimal)->first();
    expect($ipRecord)->not->toBeNull();
    expect($ipRecord->mac_address)->toBe('11:11:11:11:11:11');
    expect($ipRecord->hostname)->toBe('new-host');

    // There should be an APPROVED discovery event recorded for history
    $events = DiscoveryEvent::where('ip_addr', $ipDecimal)->get();
    expect($events->count())->toBeGreaterThanOrEqual(1);
    $found = $events->first();
    expect($found->status)->toBe(DiscoveryEvent::STATUS_APPROVED);
});

it('does not auto-import when subnet discovery is disabled (creates PENDING event)', function () {
    $customer = Customer::factory()->create();
    setTenant($customer);
    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);

    $converter = app(IpConversionService::class);
    $ip = '192.168.0.50';
    $ipDecimal = $converter->toDecimal($ip);

    // Ensure no other subnets are discoverable, then create a subnet covering our IP but discovery disabled
    \App\Models\Subnet::query()->update(['discover_subnet' => false]);
    $subnet = \App\Models\Subnet::factory()->create(['customer_id' => $customer->id, 'cidr' => '192.168.0.0/24', 'discover_subnet' => false]);

    $action = new IngestScanResultsAction();

    $results = [
        ['ip_addr' => $ip, 'mac_address' => 'aa:aa:aa:aa:aa:aa', 'hostname' => 'no-import', 'state' => 'host'],
    ];

    $action->execute($results, $agent->id);

    // ip_addresses should NOT contain the new ip (auto-import disabled)
    $ipRecord = IpAddress::where('ip_addr', $ipDecimal)->first();
    expect($ipRecord)->toBeNull();

    // There should be a PENDING discovery event recorded
    $events = DiscoveryEvent::where('ip_addr', $ipDecimal)->get();
    expect($events->count())->toBeGreaterThanOrEqual(1);
    $found = $events->first();
    expect($found->status)->toBe(DiscoveryEvent::STATUS_PENDING);
});

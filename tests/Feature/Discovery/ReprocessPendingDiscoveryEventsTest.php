<?php

use App\Models\ScanAgent;
use App\Models\Subnet;
use App\Models\DiscoveryEvent;
use App\Models\IpAddress;
use App\Models\Customer;
use App\Models\Vlan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reprocesses pending discovery events into ip_addresses when subnet discovery is enabled', function () {
    $customer = Customer::factory()->create();
    $vlan = Vlan::factory()->create(['customer_id' => $customer->id]);

    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);

    $subnet = Subnet::factory()->create([
        'vlan_id' => $vlan->id,
        'customer_id' => $customer->id,
        'subnet' => ip2long('192.168.1.0'),
        'mask' => 24,
        'discover_subnet' => true,
        'scan_agent_id' => $agent->id,
    ]);

    $ip = '192.168.1.2';
    $ipDecimal = app(\App\Services\IpConversionService::class)->toDecimal($ip);

    // Ensure no ip exists before reprocessing
    expect(IpAddress::where('ip_addr', $ipDecimal)->exists())->toBeFalse();

    // Create a pending discovery event for this agent/ip
    $event = DiscoveryEvent::create([
        'scan_agent_id' => $agent->id,
        'ip_addr' => $ipDecimal,
        'mac_address_current' => null,
        'hostname_current' => 'host-192-168-1-2',
        'status' => DiscoveryEvent::STATUS_PENDING,
        'conflict_type' => null,
        'payload_raw' => ['ip_addr' => $ip, 'hostname' => 'host-192-168-1-2'],
    ]);

    // Re-run the CLI worker to reprocess pending entries
    $this->artisan('discovery:reprocess-pending', ['--limit' => 50])->assertExitCode(0);

    // After reprocessing, IpAddress should exist and discovery event should be APPROVED
    $event->refresh();
    expect($event->status)->toBe(DiscoveryEvent::STATUS_APPROVED);

    expect(IpAddress::where('ip_addr', $ipDecimal)->exists())->toBeTrue();
});

it('keeps pending events pending when subnet discovery is disabled', function () {
    $customer = Customer::factory()->create();
    $vlan = Vlan::factory()->create(['customer_id' => $customer->id]);

    $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);

    $subnet = Subnet::factory()->create([
        'vlan_id' => $vlan->id,
        'customer_id' => $customer->id,
        'subnet' => ip2long('192.168.2.0'),
        'mask' => 24,
        'discover_subnet' => false,
        'scan_agent_id' => $agent->id,
    ]);

    $ip = '192.168.2.10';
    $ipDecimal = app(\App\Services\IpConversionService::class)->toDecimal($ip);

    $event = DiscoveryEvent::create([
        'scan_agent_id' => $agent->id,
        'ip_addr' => $ipDecimal,
        'mac_address_current' => null,
        'hostname_current' => 'host-192-168-2-10',
        'status' => DiscoveryEvent::STATUS_PENDING,
        'conflict_type' => null,
        'payload_raw' => ['ip_addr' => $ip, 'hostname' => 'host-192-168-2-10'],
    ]);

    $this->artisan('discovery:reprocess-pending', ['--limit' => 50])->assertExitCode(0);

    $event->refresh();
    expect($event->status)->toBe(DiscoveryEvent::STATUS_PENDING);
    expect(IpAddress::where('ip_addr', $ipDecimal)->exists())->toBeFalse();
});

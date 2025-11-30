<?php

use App\Models\IpAddress;
use App\Models\DiscoveryEvent;
use App\Models\ScanAgent;
use App\Models\Subnet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('approves and merges a DiscoveryEvent, updating IpAddress and status', function () {
    $agent = ScanAgent::factory()->create();
    $subnet = Subnet::factory()->create();
    $ip = '192.168.1.10';
    $ipDecimal = app(\App\Services\IpConversionService::class)->toDecimal($ip);

    $ipAddress = IpAddress::create([
        'subnet_id' => $subnet->id,
        'ip_addr' => $ipDecimal,
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'hostname' => 'old-host',
        'last_seen' => now()->subDay(),
    ]);

    $event = DiscoveryEvent::create([
        'scan_agent_id' => $agent->id,
        'ip_addr' => $ipDecimal,
        'mac_address_current' => 'ff:ee:dd:cc:bb:aa',
        'hostname_current' => 'new-host',
        'status' => DiscoveryEvent::STATUS_PENDING,
        'conflict_type' => 'MAC_MISMATCH',
        'payload_raw' => ['ip_addr' => $ip, 'mac_address' => 'ff:ee:dd:cc:bb:aa', 'hostname' => 'new-host'],
    ]);

    // Simula la bulk action Approva/Merge
    $event->refresh();
    $ipAddress->refresh();
    $ipAddress->mac_address = $event->mac_address_current;
    $ipAddress->hostname = $event->hostname_current;
    $ipAddress->save();
    $event->status = DiscoveryEvent::STATUS_APPROVED;
    $event->save();

    $ipAddress->refresh();
    $event->refresh();

    expect($ipAddress->mac_address)->toBe('ff:ee:dd:cc:bb:aa');
    expect($ipAddress->hostname)->toBe('new-host');
    expect($event->status)->toBe(DiscoveryEvent::STATUS_APPROVED);
});

it('soft deletes a DiscoveryEvent when discarded', function () {
    $agent = ScanAgent::factory()->create();
    $subnet = Subnet::factory()->create();
    $ip = '192.168.1.20';
    $ipDecimal = app(\App\Services\IpConversionService::class)->toDecimal($ip);

    $event = DiscoveryEvent::create([
        'scan_agent_id' => $agent->id,
        'ip_addr' => $ipDecimal,
        'mac_address_current' => '11:22:33:44:55:66',
        'hostname_current' => 'discard-host',
        'status' => DiscoveryEvent::STATUS_PENDING,
        'conflict_type' => 'MAC_MISMATCH',
        'payload_raw' => ['ip_addr' => $ip, 'mac_address' => '11:22:33:44:55:66', 'hostname' => 'discard-host'],
    ]);

    // Simula la bulk action Scarta (soft delete)
    $event->delete();

    $trashed = DiscoveryEvent::withTrashed()->find($event->id);
    expect($trashed)->not->toBeNull();
    expect($trashed->deleted_at)->not->toBeNull();
});

it('can render the discovery events list page', function () {
    $user = \App\Models\User::factory()->create();

    $this->actingAs($user)
        ->get(App\Filament\Clusters\Discovery\Resources\DiscoveryEventResource::getUrl('index'))
        ->assertSuccessful();
});

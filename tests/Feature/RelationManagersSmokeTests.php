<?php

use function Pest\Livewire\livewire;

it('mounts subnet view page with ip addresses relation manager present', function () {
    $this->actingAs(\App\Models\User::factory()->create());

    $subnet = \App\Models\Subnet::create([
        'cidr' => '10.253.0.0/24',
        'description' => 'Smoke test subnet',
    ]);

    livewire(\App\Filament\Clusters\Ipam\Resources\Subnets\Pages\ViewSubnet::class, ['record' => $subnet->id])
        ->assertOk();
});

it('mounts rack view page with devices relation manager present', function () {
    $this->actingAs(\App\Models\User::factory()->create());

    $rack = \App\Models\Rack::create([
        'name' => 'Smoke Rack',
        'units' => 42,
    ]);

    livewire(\App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class, ['record' => $rack->id])
        ->assertOk();
});

it('mounts edit scan agent page and ensures agent logs relation manager loads', function () {
    $this->actingAs(\App\Models\User::factory()->create());

    $customer = \App\Models\Customer::create(['name' => 'Smoke Cust']);

    $agent = \App\Models\ScanAgent::create([
        'name' => 'Smoke Agent',
        'active' => true,
        'customer_id' => $customer->id,
    ]);

    livewire(\App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages\EditScanAgent::class, ['record' => $agent->id])
        ->assertOk();
});

it('generates an agent API key from the Edit page', function () {
    $this->actingAs(\App\Models\User::factory()->create());

    $customer = \App\Models\Customer::create(['name' => 'Smoke Cust Key']);

    $agent = \App\Models\ScanAgent::create([
        'name' => 'Smoke Agent Key',
        'active' => true,
        'customer_id' => $customer->id,
    ]);

    $component = livewire(\App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages\EditScanAgent::class, ['record' => $agent->id])
        ->assertOk();

    // initially there should be no tokens
    $agent->refresh();
    expect($agent->tokens()->count())->toBe(0);

    // call page action that generates an agent key
    $component->call('generateAgentKey');

    $agent->refresh();

    // expect at least one token now exists
    expect($agent->tokens()->count())->toBeGreaterThan(0);

    // the page should have the tempNewKey available immediately (visible in modal)
    $component->assertSet('tempNewKey', fn ($val) => ! empty($val));
});

// test for the setup UI was removed; this UI is now covered by more focused tests and visual checks

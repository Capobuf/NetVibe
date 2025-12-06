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

    $agent = \App\Models\ScanAgent::create([
        'name' => 'Smoke Agent',
        'active' => true,
    ]);

    livewire(\App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages\EditScanAgent::class, ['record' => $agent->id])
        ->assertOk();
});

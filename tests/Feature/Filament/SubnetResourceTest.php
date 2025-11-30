<?php

use App\Filament\Clusters\Ipam\Resources\Subnets\SubnetResource;
use App\Models\User;
use Livewire\Livewire;

it('can render the subnet list', function () {
    // Create an admin user for authentication
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(SubnetResource::getUrl('index'))
        ->assertSuccessful();
});

it('can create a subnet', function () {
    $user = User::factory()->create();
    $vrf = \App\Models\Vrf::factory()->create();

    // Create using model factory / Eloquent directly as a first-step Filament-compatible test
    \App\Models\Subnet::create([
        'cidr' => '10.0.0.0/24',
        'vrf_id' => $vrf->id,
        'name' => 'Test Subnet',
    ]);

    $this->assertDatabaseHas('subnets', [
        'name' => 'Test Subnet',
    ]);
});

<?php

use App\Filament\Clusters\Ipam\Resources\IpAddresses\Pages\CreateIpAddress;
use App\Filament\Clusters\Ipam\Resources\IpAddresses\IpAddressResource;
use App\Models\User;
use App\Models\IpAddress;
use App\Models\Subnet;
use function Pest\Livewire\livewire;

it('can render the ip address creation form with correct fields', function () {
    $user = User::factory()->create();
    $subnet = Subnet::factory()->create(); // Assicura che esista una subnet

    $this->actingAs($user)
        ->get(IpAddressResource::getUrl('create'))
        ->assertSuccessful();

    livewire(CreateIpAddress::class)
        ->assertFormExists()
        ->assertFormFieldExists('ip_addr')
        ->assertFormFieldExists('type') // Questo verifica che lo Schema funzioni!
        ->assertFormFieldExists('subnet_id');
});

it('can create an ip address using the enum', function () {
    $user = User::factory()->create();
    $subnet = Subnet::factory()->create();

    // ensure we are authenticated and the create page is mounted — load the page first
    $this->actingAs($user)
        ->get(IpAddressResource::getUrl('create'))
        ->assertSuccessful();

    $c = livewire(CreateIpAddress::class)
        ->set('data.ip_addr', '192.168.1.50')
        ->set('data.subnet_id', $subnet->id)
        // Livewire/Filament expects the enum-backed value for select inputs
        ->set('data.type', \App\Enums\IpAddressType::Gateway->value)
        ->set('data.description', 'Test Gateway')
        // toggles are required in the form schema — set to pass validation
        ->set('data.exclude_ping', true)
        // form schema no longer includes ptr related fields
        ->call('create');

    $this->assertDatabaseHas('ip_addresses', [
        'ip_addr' => 3232235826, // 192.168.1.50 in decimal
        'type' => 'gateway', // Enum backed value
    ]);
});
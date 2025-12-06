<?php

use App\Filament\Clusters\Ipam\Resources\Subnets\Pages\CreateSubnet;
use App\Filament\Clusters\Racks\Resources\Devices\Pages\CreateDevice;
use App\Models\Customer;
use App\Models\Vlan;
use function Pest\Livewire\livewire;

it('shows customer_id in create-subnet form when in god mode', function () {
    // no tenant in session

    $this->actingAs(createAdminUser());

    // mount the Filament create page Livewire component
    livewire(CreateSubnet::class)
        ->assertFormFieldIsVisible('customer_id');
});

it('hides customer_id in create-subnet form and persists session tenant', function () {
    $this->actingAs(createAdminUser());

    $customer = Customer::factory()->create();
    $vlan = Vlan::factory()->create(['customer_id' => $customer->id]);

    // set session tenant
    $this->withSession(['tenant_id' => $customer->id]);

    $component = livewire(CreateSubnet::class)
        ->assertFormFieldIsHidden('customer_id')
        // fill required fields: vlan_id and cidr (set nested form state)
        ->set('data.vlan_id', $vlan->id)
        ->set('data.cidr', '192.168.100.0/24')
        ->call('create')
        ->assertHasNoErrors();

    // ensure DB created with tenant id
    $this->assertDatabaseHas('subnets', [
        'cidr' => '192.168.100.0/24',
        'customer_id' => $customer->id,
    ]);
});

it('device form filter hides customer_id with default when tenant set', function () {
    $this->actingAs(createAdminUser());

    $customer = Customer::factory()->create();
    $this->withSession(['tenant_id' => $customer->id]);

    // The DeviceForm is a schema page component; test by mounting the Resource create page
    $component = livewire(CreateDevice::class)
        ->assertFormFieldIsHidden('customer_id');

    // the hidden input should be prefilled with the tenant id (rendered HTML contains the value)
    $this->assertStringContainsString((string) $customer->id, $component->html());
});

it('creates location for active tenant automatically', function () {
    $this->actingAs(createAdminUser());

    $customer = Customer::factory()->create();
    $this->withSession(['tenant_id' => $customer->id]);

    $component = livewire(\App\Filament\Clusters\Organization\Resources\Locations\Pages\CreateLocation::class)
        ->assertFormFieldIsHidden('customer_id')
        ->set('data.name', 'Tenant Location')
        ->call('create')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('locations', [
        'name' => 'Tenant Location',
        'customer_id' => $customer->id,
    ]);
});

it('creates scan agent for active tenant automatically', function () {
    $this->actingAs(createAdminUser());

    $customer = Customer::factory()->create();
    $this->withSession(['tenant_id' => $customer->id]);

    $component = livewire(\App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages\CreateScanAgent::class)
        ->assertFormFieldIsHidden('customer_id')
        ->set('data.name', 'Tenant Agent')
        ->call('create')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('scan_agents', [
        'name' => 'Tenant Agent',
        'customer_id' => $customer->id,
    ]);
});

// Helper for an admin user in tests
function createAdminUser()
{
    // Use existing user factory; create a user with admin role (if roles are used).
    // Fallback to creating a plain user.
    return \App\Models\User::factory()->create();
}

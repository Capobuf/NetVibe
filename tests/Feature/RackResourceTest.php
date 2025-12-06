<?php

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(createAdminUser());
});

it('can render rack view page', function () {
    $rack = \App\Models\Rack::create(['name' => 'Render Rack', 'units' => 42]);

    livewire(\App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class, ['record' => $rack->id])
        ->assertOk();
});

it('shows create action on empty slot', function () {
    $rack = \App\Models\Rack::create(['name' => 'Action Rack', 'units' => 42]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class, ['record' => $rack->id])
        ->assertOk();

    $actionName = "create-device-{$rack->id}-1";

    // Mount the slot action via TestAction wrapper and assert it's mounted
    $component->mountAction(
        \Filament\Actions\Testing\TestAction::make($actionName)
            ->arguments(['data' => ['action_type' => 'create', 'rack_id' => $rack->id, 'rack_position' => 1]])
            ->schemaComponent("slot_actions_{$rack->id}_1")
    );

    $component->assertActionMounted(\Filament\Actions\Testing\TestAction::make($actionName)->schemaComponent("slot_actions_{$rack->id}_1"));
});

it('toggles form fields based on action type', function () {
    $rack = \App\Models\Rack::create(['name' => 'Toggle Rack', 'units' => 42]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class, ['record' => $rack->id])
        ->assertOk();

    $actionName = "create-device-{$rack->id}-2";

    $component->mountAction(
        \Filament\Actions\Testing\TestAction::make($actionName)
            ->arguments(['data' => ['action_type' => 'create', 'rack_id' => $rack->id, 'rack_position' => 2]])
            ->schemaComponent("slot_actions_{$rack->id}_2")
    );

    $component->assertActionMounted(\Filament\Actions\Testing\TestAction::make($actionName)->schemaComponent("slot_actions_{$rack->id}_2"));

    // Start in CREATE mode: should show create fields and not the mount select
    $component->set('mountedActions.0.data.action_type', 'create')
        ->assertMountedActionModalSee('Hardware Model')
        ->assertMountedActionModalDontSee('Select Orphan Device');

    // Switch to MOUNT mode and verify mount-specific UI shows
    $component->set('mountedActions.0.data.action_type', 'mount')
        ->assertMountedActionModalSee('Select Orphan Device')
        ->assertMountedActionModalDontSee('Hardware Model');
});

it('shows only orphan devices for same customer in mount select', function () {
    // Setup customers & rack
    $custA = \App\Models\Customer::create(['name' => 'C-A']);
    $custB = \App\Models\Customer::create(['name' => 'C-B']);

    $loc = \App\Models\Location::create(['name' => 'Loc', 'customer_id' => $custA->id]);
    $rack = \App\Models\Rack::create(['name' => 'R', 'location_id' => $loc->id]);

    // Orphan owned by same customer -> should be available
    $model = \App\Models\DeviceModel::create(['brand' => 'X', 'model' => 'm', 'type' => 'server', 'u_size' => 1]);
    $orphanA = \App\Models\Device::create([
        'hostname' => 'orphan-a',
        'device_model_id' => $model->id,
        'customer_id' => $custA->id,
        'rack_id' => null,
    ]);

    // Orphan owned by different customer -> shouldn't appear
    $orphanB = \App\Models\Device::create([
        'hostname' => 'orphan-b',
        'device_model_id' => $model->id,
        'customer_id' => $custB->id,
        'rack_id' => null,
    ]);

    // Occupied device for the same customer -> shouldn't appear
    $occupied = \App\Models\Device::create([
        'hostname' => 'occupied-a',
        'device_model_id' => $model->id,
        'customer_id' => $custA->id,
        'rack_id' => $rack->id,
    ]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class, ['record' => $rack->id])
        ->assertOk();

    $actionName = "create-device-{$rack->id}-3";
    $component->mountAction(
        \Filament\Actions\Testing\TestAction::make($actionName)
            ->arguments(['data' => ['action_type' => 'mount', 'rack_id' => $rack->id, 'rack_position' => 3]])
            ->schemaComponent("slot_actions_{$rack->id}_3")
    );

    $component->assertActionMounted(\Filament\Actions\Testing\TestAction::make($actionName)->schemaComponent("slot_actions_{$rack->id}_3"));

    // Switch to mount mode so the device select is rendered
    $component->set('mountedActions.0.data.action_type', 'mount')
        ->assertMountedActionModalSee('orphan-a');
    $component->assertMountedActionModalDontSee('orphan-b');
    $component->assertMountedActionModalDontSee('occupied-a');
});

it('prevents mounting if space overlaps', function () {
    $rack = \App\Models\Rack::create(['name' => 'Overlap Rack', 'units' => 42]);

    $bigModel = \App\Models\DeviceModel::create(['brand' => 'Big', 'model' => 'big-2u', 'type' => 'server', 'u_size' => 2]);

    // Already mounted 2U device occupying U10-11
    $mounted = \App\Models\Device::create([
        'hostname' => 'exists-2u',
        'device_model_id' => $bigModel->id,
        'rack_id' => $rack->id,
        'rack_position' => 10,
    ]);

    // Orphan 2U device we will try to mount overlapping at U9 (would occupy 9-10)
    $orphan = \App\Models\Device::create([
        'hostname' => 'to-mount-2u',
        'device_model_id' => $bigModel->id,
        'rack_id' => null,
    ]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class, ['record' => $rack->id])
        ->assertOk();

    $actionName = "create-device-{$rack->id}-9";
    $component->mountAction(
        \Filament\Actions\Testing\TestAction::make($actionName)
            ->arguments(['data' => ['action_type' => 'mount', 'rack_id' => $rack->id, 'rack_position' => 9, 'device_id' => $orphan->id]])
            ->schemaComponent("slot_actions_{$rack->id}_9")
    );

    $component->assertActionMounted(\Filament\Actions\Testing\TestAction::make($actionName)->schemaComponent("slot_actions_{$rack->id}_9"));

    // Ensure the mounted action state is populated (set explicit values to avoid relying on arguments)
    $component->set('mountedActions.0.data.action_type', 'mount')
        ->set('mountedActions.0.data.device_id', $orphan->id)
        ->set('mountedActions.0.data.rack_id', $rack->id)
        ->set('mountedActions.0.data.rack_position', 9)
        ->callMountedAction()
        ->assertNotified('Space conflict');
});

it('can create a new device in rack', function () {
    $rack = \App\Models\Rack::create(['name' => 'Create Rack', 'units' => 42]);

    $model = \App\Models\DeviceModel::create(['brand' => 'Make', 'model' => 'm1', 'type' => 'server', 'u_size' => 1]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class, ['record' => $rack->id])
        ->assertOk();

    $actionName = "create-device-{$rack->id}-5";
    $component->mountAction(
        \Filament\Actions\Testing\TestAction::make($actionName)
            ->arguments(['data' => ['action_type' => 'create', 'rack_id' => $rack->id, 'rack_position' => 5]])
            ->schemaComponent("slot_actions_{$rack->id}_5")
    );

    $component->assertActionMounted(\Filament\Actions\Testing\TestAction::make($actionName)->schemaComponent("slot_actions_{$rack->id}_5"));

    // Fill the create form fields for a new device and submit
    $component->set('mountedActions.0.data.hostname', 'new-device')
        ->set('mountedActions.0.data.device_model_id', $model->id)
        ->callMountedAction()
        ->assertNotified();

    $fresh = \App\Models\Device::where('hostname', 'new-device')->first();
    expect($fresh)->not->toBeNull();
    expect($fresh->rack_id)->toBe($rack->id);
    expect($fresh->rack_position)->toBe(5);
});

it('can mount an existing device', function () {
    $rack = \App\Models\Rack::create(['name' => 'Mount Rack', 'units' => 42]);

    $model = \App\Models\DeviceModel::create(['brand' => 'Mounty', 'model' => 'm3', 'type' => 'server', 'u_size' => 1]);

    $orphan = \App\Models\Device::create([
        'hostname' => 'mount-me',
        'device_model_id' => $model->id,
        'rack_id' => null,
    ]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class, ['record' => $rack->id])
        ->assertOk();

    $actionName = "create-device-{$rack->id}-6";
    $component->mountAction(
        \Filament\Actions\Testing\TestAction::make($actionName)
            ->arguments(['data' => ['action_type' => 'mount', 'rack_id' => $rack->id, 'rack_position' => 6, 'device_id' => $orphan->id]])
            ->schemaComponent("slot_actions_{$rack->id}_6")
    );

    $component->assertActionMounted(\Filament\Actions\Testing\TestAction::make($actionName)->schemaComponent("slot_actions_{$rack->id}_6"));

    // Make sure mounted action state is set correctly, then execute
    $component->set('mountedActions.0.data.action_type', 'mount')
        ->set('mountedActions.0.data.device_id', $orphan->id)
        ->set('mountedActions.0.data.rack_id', $rack->id)
        ->set('mountedActions.0.data.rack_position', 6)
        ->callMountedAction()
        ->assertNotified();

    $fresh = \App\Models\Device::find($orphan->id);
    expect($fresh->rack_id)->toBe($rack->id);
    expect($fresh->rack_position)->toBe(6);
});

it('auto-fills customer when in tenant mode', function () {
    $cust = \App\Models\Customer::create(['name' => 'TenantCust']);

    // set session tenant context
    $this->withSession(['tenant_id' => $cust->id]);

    // Ensure the rack is owned by the tenant so the resource's tenant scope can resolve it
    $rack = \App\Models\Rack::create(['name' => 'Tenant Rack', 'units' => 42, 'customer_id' => $cust->id]);
    $model = \App\Models\DeviceModel::create(['brand' => 'T', 'model' => 't1', 'type' => 'server', 'u_size' => 1]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class, ['record' => $rack->id])
        ->assertOk();

    $actionName = "create-device-{$rack->id}-7";
    $component->mountAction(
        \Filament\Actions\Testing\TestAction::make($actionName)
            ->arguments(['data' => ['action_type' => 'create', 'rack_id' => $rack->id, 'rack_position' => 7]])
            ->schemaComponent("slot_actions_{$rack->id}_7")
    );

    $component->assertActionMounted(\Filament\Actions\Testing\TestAction::make($actionName)->schemaComponent("slot_actions_{$rack->id}_7"));

    // Create without explicitly providing customer_id — session tenant should be used
    $component->set('mountedActions.0.data.hostname', 'tenant-device')
        ->set('mountedActions.0.data.device_model_id', $model->id)
        ->set('mountedActions.0.data.customer_id', null)
        ->callMountedAction()
        ->assertNotified();

    $fresh = \App\Models\Device::where('hostname', 'tenant-device')->first();
    expect($fresh)->not->toBeNull();
    expect($fresh->customer_id)->toBe($cust->id);
});

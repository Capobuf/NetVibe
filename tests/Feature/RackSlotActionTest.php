<?php

use function Pest\Livewire\livewire;

it('toggles groups when switching create vs mount in the rack slot action', function () {
    $this->actingAs(createAdminUser());

    $rack = \App\Models\Rack::create(['name' => 'Test Rack', 'units' => 42]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class, ['record' => $rack->id])
        ->assertOk();

    $actionName = "create-device-{$rack->id}-42";

    // The action should exist on the page — assert it first (adds clarity if mount fails)
    // Mount the action by initializing the mountedActions state directly. Some
    // actions live inside schema components and are not discoverable by
    // assertActionExists()/mountAction() in the testing harness — set the state
    // directly to simulate mounting the action.
    $component->mountAction(
        \Filament\Actions\Testing\TestAction::make($actionName)
            ->arguments(['data' => ['action_type' => 'mount', 'rack_id' => $rack->id, 'rack_position' => 42]])
            ->schemaComponent("slot_actions_{$rack->id}_42")
    );
    // mounted action mounted
    // mounted action simulated via mountedActions state

    // When we switch to mount, the mount group should be visible (modal contains the Select)
    // and the Create form fields (e.g. hardware model) should be hidden. Use modal-scoped
    // assertions to avoid relying on default form names which differ when actions are
    // rendered inside infolist schemas.
    // The mounted action state is kept under `mountedActions` on the Livewire component
    // when actions are defined inside an infolist schema. Update the mounted action
    // arguments to set the action_type to 'mount' (use index 0 since this is the first
    // mounted action in the test harness) and then assert the page includes the
    // select and hides the create form label.
    // Ensure the action is mounted first and has the expected name
    $component->assertActionMounted(\Filament\Actions\Testing\TestAction::make($actionName)->schemaComponent("slot_actions_{$rack->id}_42"));

    // ensure the form data switches to mount mode so the mount group is displayed
    $component->set('mountedActions.0.data.action_type', 'mount')
        ->assertMountedActionModalSee('Select Orphan Device')
        ->assertMountedActionModalDontSee('Hardware Model');
});

it('mount select lists only orphan devices for the rack customer', function () {
    $this->actingAs(createAdminUser());

    // Setup customers & rack
    $custA = \App\Models\Customer::create(['name' => 'A']);
    $custB = \App\Models\Customer::create(['name' => 'B']);

    $loc = \App\Models\Location::create(['name' => 'Loc', 'customer_id' => $custA->id]);
    $rack = \App\Models\Rack::create(['name' => 'R', 'location_id' => $loc->id]);

    // Orphan owned by same customer -> should be available
    $orphanA = \App\Models\Device::create([
        'hostname' => 'orphan-a',
        'device_model_id' => \App\Models\DeviceModel::create(['brand' => 'X','model' => 'm','type' => 'server','u_size' => 1])->id,
        'customer_id' => $custA->id,
        'rack_id' => null,
    ]);

    // Orphan owned by different customer -> shouldn't appear
    $orphanB = \App\Models\Device::create([
        'hostname' => 'orphan-b',
        'device_model_id' => \App\Models\DeviceModel::create(['brand' => 'Y','model' => 'm','type' => 'server','u_size' => 1])->id,
        'customer_id' => $custB->id,
        'rack_id' => null,
    ]);

    // Occupied device for the same customer -> shouldn't appear
    $occupied = \App\Models\Device::create([
        'hostname' => 'occupied-a',
        'device_model_id' => \App\Models\DeviceModel::create(['brand' => 'Z','model' => 'm','type' => 'switch','u_size' => 1])->id,
        'customer_id' => $custA->id,
        'rack_id' => $rack->id,
    ]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class, ['record' => $rack->id])
        ->assertOk();

    $actionName = "create-device-{$rack->id}-42";
    $component->mountAction(
        \Filament\Actions\Testing\TestAction::make($actionName)
            ->arguments(['data' => ['action_type' => 'mount', 'rack_id' => $rack->id, 'rack_position' => 42]])
            ->schemaComponent("slot_actions_{$rack->id}_42")
    );
    // mounted action mounted
    // mounted action simulated via mountedActions state

    // Switch to mount mode so the device select is rendered
    // Ensure the mounted action is in 'mount' mode by updating the nested action
    // arguments, then check the component HTML contains the expected options.
    $component->assertActionMounted(\Filament\Actions\Testing\TestAction::make($actionName)->schemaComponent("slot_actions_{$rack->id}_42"));

    // ensure the mounted form switches to mount mode and the orphan option is visible
    $component->set('mountedActions.0.data.action_type', 'mount')
        ->assertMountedActionModalSee('orphan-a');
    $component->assertMountedActionModalDontSee('orphan-b');
    $component->assertMountedActionModalDontSee('occupied-a');
});

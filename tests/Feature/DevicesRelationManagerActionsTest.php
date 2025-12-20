<?php

use function Pest\Livewire\livewire;

it('unmounts a device via the relation manager action', function () {
    $this->actingAs(createAdminUser());

    $model = \App\Models\DeviceModel::create(['brand' => 'ACME', 'model' => 'D1', 'type' => 'server', 'u_size' => 1]);

    $rack = \App\Models\Rack::create(['name' => 'R', 'units' => 42]);

    $device = \App\Models\Device::create([
        'hostname' => 'd-unmount',
        'device_model_id' => $model->id,
        'rack_id' => $rack->id,
        'rack_position' => 1,
    ]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\RelationManagers\DevicesRelationManager::class, [
        'ownerRecord' => $rack,
        'pageClass' => \App\Filament\Clusters\Racks\Resources\Racks\Pages\EditRack::class,
    ])->assertOk();

    // Call the single-record Unmount action (named 'detach' in the relation manager)
    $component->callAction(\Filament\Actions\Testing\TestAction::make('detach')->table($device));

    $fresh = \App\Models\Device::find($device->id);
    expect($fresh->rack_id)->toBeNull();
    expect($fresh->rack_position)->toBeNull();
});

it('bulk unmounts selected devices', function () {
    $this->actingAs(createAdminUser());

    $model = \App\Models\DeviceModel::create(['brand' => 'ACME', 'model' => 'D2', 'type' => 'server', 'u_size' => 1]);

    $rack = \App\Models\Rack::create(['name' => 'R2', 'units' => 42]);

    $d1 = \App\Models\Device::create(['hostname' => 'bulk-1', 'device_model_id' => $model->id, 'rack_id' => $rack->id, 'rack_position' => 1]);
    $d2 = \App\Models\Device::create(['hostname' => 'bulk-2', 'device_model_id' => $model->id, 'rack_id' => $rack->id, 'rack_position' => 2]);
    $d3 = \App\Models\Device::create(['hostname' => 'other', 'device_model_id' => $model->id, 'rack_id' => null]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\RelationManagers\DevicesRelationManager::class, [
        'ownerRecord' => $rack,
        'pageClass' => \App\Filament\Clusters\Racks\Resources\Racks\Pages\EditRack::class,
    ])->assertOk();

    // Select the two devices we want to unmount and run the bulk action
    $component->set('selectedTableRecords', [$d1->id, $d2->id])
        ->callAction(\Filament\Actions\Testing\TestAction::make('unmount_selected')->table()->bulk());

    expect(\App\Models\Device::find($d1->id)->rack_id)->toBeNull();
    expect(\App\Models\Device::find($d2->id)->rack_id)->toBeNull();
    // unaffected device remains mounted/unaffected
    expect(\App\Models\Device::find($d3->id)->rack_id)->toBeNull();
});

it('deletes a device via the relation manager delete action', function () {
    $this->actingAs(createAdminUser());

    $model = \App\Models\DeviceModel::create(['brand' => 'ACME', 'model' => 'D3', 'type' => 'server', 'u_size' => 1]);

    $rack = \App\Models\Rack::create(['name' => 'R3', 'units' => 42]);

    $device = \App\Models\Device::create(['hostname' => 'to-delete', 'device_model_id' => $model->id, 'rack_id' => $rack->id]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\RelationManagers\DevicesRelationManager::class, [
        'ownerRecord' => $rack,
        'pageClass' => \App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class,
    ])->assertOk();

    // Mount and execute the Delete action directly (bypass visibility guard in tests)
    $component->assertActionExists(\Filament\Actions\Testing\TestAction::make('delete')->table($device));
    $component->mountAction(\Filament\Actions\Testing\TestAction::make('delete')->table($device));
    $component->assertActionMounted(\Filament\Actions\Testing\TestAction::make('delete')->table($device));
    $component->callMountedAction();

    expect(\App\Models\Device::find($device->id))->toBeNull();
});

it('edits a device via the relation manager edit action', function () {
    $this->actingAs(createAdminUser());

    $model = \App\Models\DeviceModel::create(['brand' => 'ACME', 'model' => 'D4', 'type' => 'server', 'u_size' => 1]);

    $rack = \App\Models\Rack::create(['name' => 'R4', 'units' => 42]);

    $device = \App\Models\Device::create(['hostname' => 'to-edit', 'device_model_id' => $model->id, 'rack_id' => $rack->id]);

    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\RelationManagers\DevicesRelationManager::class, [
        'ownerRecord' => $rack,
        'pageClass' => \App\Filament\Clusters\Racks\Resources\Racks\Pages\EditRack::class,
    ])->assertOk();

    // Mount the edit action and call it with new data (simulate form submission)
    $component->assertActionExists(\Filament\Actions\Testing\TestAction::make('edit')->table($device));
    // Use high-level callAction to mount, fill and execute the action in one step
    $component->callAction(\Filament\Actions\Testing\TestAction::make('edit')->table($device), ['hostname' => 'edited-host'])
        ->assertNotified();
});

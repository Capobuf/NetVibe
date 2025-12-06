<?php

use function Pest\Livewire\livewire;

it('mounts rack create device action using DeviceForm schema without throwing relationship errors', function () {
    $this->actingAs(createAdminUser());

    $rack = \App\Models\Rack::create(['name' => 'Context Rack', 'units' => 42]);

    // Mount the relation action on the ViewRack page (action uses DeviceForm schema)
    $component = livewire(\App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack::class, ['record' => $rack->id])
        ->assertOk();

    // The action name is create-device-{rack.id}-{unit} — try mount the top U action
    $actionName = "create-device-{$rack->id}-42";

    // Mount action (show modal) to ensure no LogicException occurs when rendering DeviceForm schema in Rack context
    $component->mountAction($actionName);
});

<?php

use function Pest\Livewire\livewire;

it('mounts the edit device page and loads ports relation manager without errors', function () {
    $this->actingAs(createAdminUser());

    $model = \App\Models\DeviceModel::create([
        'brand' => 'ACME',
        'model' => 'SmokeTest',
        'type' => \App\Enums\DeviceType::Switch->value,
        'u_size' => 1,
        'ethernet_ports' => 1,
        'sfp_ports' => 0,
    ]);

    $device = \App\Models\Device::create([
        'hostname' => 'smoke-device',
        'device_model_id' => $model->id,
    ]);

    // Mount the EditDevice page (passes record id) — this will exercise RelationManagers
    livewire(\App\Filament\Clusters\Racks\Resources\Devices\Pages\EditDevice::class, ['record' => $device->id])
        ->assertOk();
});

<?php

declare(strict_types=1);

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(createAdminUser());
});

it('mounts the racks devices list page without errors', function () {
    // Ensure at least one device exists so the table renders data
    $model = \App\Models\DeviceModel::create([
        'brand' => 'TestBrand',
        'model' => 'TestModel',
        'type' => \App\Enums\DeviceType::Switch->value,
    ]);

    \App\Models\Device::create([
        'hostname' => 'dev-list',
        'device_model_id' => $model->id,
    ]);

    livewire(\App\Filament\Clusters\Racks\Resources\Devices\Pages\ListDevices::class)
        ->assertOk();
});


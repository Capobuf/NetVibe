<?php

namespace App\Filament\Clusters\Racks\Resources\DeviceModels\Pages;

use App\Filament\Clusters\Racks\Resources\DeviceModels\DeviceModelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDeviceModel extends EditRecord
{
    protected static string $resource = DeviceModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

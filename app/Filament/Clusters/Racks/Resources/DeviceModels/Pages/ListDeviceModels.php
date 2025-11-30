<?php

namespace App\Filament\Clusters\Racks\Resources\DeviceModels\Pages;

use App\Filament\Clusters\Racks\Resources\DeviceModels\DeviceModelResource;
use Filament\Resources\Pages\ListRecords;

class ListDeviceModels extends ListRecords
{
    protected static string $resource = DeviceModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make()
                ->label('New')
                ->icon('heroicon-o-plus')
                ->button()
                ->slideOver(),
        ];
    }
}

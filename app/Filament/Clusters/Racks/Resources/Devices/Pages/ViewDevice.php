<?php

namespace App\Filament\Clusters\Racks\Resources\Devices\Pages;

use App\Filament\Clusters\Racks\Resources\Devices\DeviceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDevice extends ViewRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\EditAction::make()
                ->slideOver()
                ->label('Edit Device'),
        ];
    }
}


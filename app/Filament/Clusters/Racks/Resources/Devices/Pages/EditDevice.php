<?php

namespace App\Filament\Clusters\Racks\Resources\Devices\Pages;

use App\Filament\Clusters\Racks\Resources\Devices\DeviceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Device')
                ->modalDescription('This will permanently delete this device. This action cannot be undone.')
                ->color('danger'),
        ];
    }
}

<?php

namespace App\Filament\Resources\CableTypes\Pages;

use App\Filament\Resources\CableTypes\CableTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCableType extends EditRecord
{
    protected static string $resource = CableTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}

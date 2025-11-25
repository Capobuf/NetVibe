<?php

namespace App\Filament\Resources\Vlans\Pages;

use App\Filament\Resources\Vlans\VlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVlan extends EditRecord
{
    protected static string $resource = VlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

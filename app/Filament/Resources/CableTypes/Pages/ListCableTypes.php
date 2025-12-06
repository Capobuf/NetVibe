<?php

namespace App\Filament\Resources\CableTypes\Pages;

use App\Filament\Resources\CableTypes\CableTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCableTypes extends ListRecords
{
    protected static string $resource = CableTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->slideOver(),
        ];
    }
}

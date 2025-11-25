<?php

namespace App\Filament\Resources\Vrves\Pages;

use App\Filament\Resources\Vrves\VrfResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVrves extends ListRecords
{
    protected static string $resource = VrfResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

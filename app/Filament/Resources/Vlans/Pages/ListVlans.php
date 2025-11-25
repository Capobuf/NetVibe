<?php

namespace App\Filament\Resources\Vlans\Pages;

use App\Filament\Resources\Vlans\VlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVlans extends ListRecords
{
    protected static string $resource = VlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Clusters\Racks\Resources\Racks\Pages;

use App\Filament\Clusters\Racks\Resources\Racks\RackResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;

class ListRacks extends ListRecords
{
    protected static string $resource = RackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New')
                ->icon('heroicon-o-plus')
                ->button()
                ->slideOver(),
        ];
    }
}

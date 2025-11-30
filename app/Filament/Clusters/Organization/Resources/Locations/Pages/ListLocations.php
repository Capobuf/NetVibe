<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Organization\Resources\Locations\Pages;

use App\Filament\Clusters\Organization\Resources\Locations\LocationResource;
use Filament\Resources\Pages\ListRecords;

class ListLocations extends ListRecords
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make()
                ->label('New')
                ->icon('heroicon-o-plus')
                ->button()
                ->slideOver()
                ->modalWidth('md'),
        ];
    }
}

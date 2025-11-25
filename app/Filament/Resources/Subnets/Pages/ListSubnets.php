<?php

namespace App\Filament\Resources\Subnets\Pages;

use App\Filament\Resources\Subnets\SubnetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubnets extends ListRecords
{
    protected static string $resource = SubnetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

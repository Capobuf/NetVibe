<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets\Pages;

use App\Filament\Clusters\Ipam\Resources\Subnets\SubnetResource;
use Filament\Resources\Pages\ListRecords;

class ListSubnets extends ListRecords
{
    protected static string $resource = SubnetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make()
                ->label('New')
                ->icon('heroicon-o-plus')
                ->button()
                ->slideOver(),
        ];
    }
}

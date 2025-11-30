<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets\Pages;

use App\Filament\Clusters\Ipam\Resources\Subnets\SubnetResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSubnet extends ViewRecord
{
    protected static string $resource = SubnetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\EditAction::make()
                ->slideOver()
                ->icon('heroicon-m-pencil-square')
                ->label('Edit Subnet'),
            // DeleteAction removed from view page per CRUD UX policy - delete must be available only on Edit page
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            \App\Filament\Clusters\Ipam\Resources\Subnets\Widgets\IpAddressVisualGrid::class,
        ];
    }
}

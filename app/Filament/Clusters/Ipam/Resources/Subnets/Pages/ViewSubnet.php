<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets\Pages;

use App\Filament\Clusters\Ipam\Resources\Subnets\SubnetResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSubnet extends ViewRecord
{
    protected static string $resource = SubnetResource::class;

    protected function getFooterWidgets(): array
    {
        return [
            \App\Filament\Clusters\Ipam\Resources\Subnets\Widgets\IpAddressVisualGrid::class,
        ];
    }
}

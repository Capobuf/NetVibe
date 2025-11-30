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
        ];
    }

    // intentionally removed footer widgets (IpAddressVisualGrid) because the visual map
    // widget was unreliable and caused rendering issues. The Subnet view now focuses
    // on the relation manager 'IP Table' for IP address operations.
}

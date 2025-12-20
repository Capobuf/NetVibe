<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets\Pages;

use App\Filament\Clusters\Ipam\Resources\Subnets\SubnetResource;
use App\Filament\Clusters\Ipam\Resources\Subnets\Widgets\SubnetStatsOverview;
use App\Filament\Clusters\Ipam\Resources\Subnets\Widgets\SubnetIpCompositionChart;
use Filament\Resources\Pages\ViewRecord;

class ViewSubnet extends ViewRecord
{
    protected static string $resource = SubnetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\EditAction::make()
                ->slideOver()
                ->icon('heroicon-o-pencil-square')
                ->label('Edit Subnet'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        // Only show the stats cards in the header (top of page)
        return [
            SubnetStatsOverview::make([
                'record' => $this->record,
            ]),
        ];
    }

    protected function getFooterWidgets(): array
    {
        // Ensure the IP Composition chart renders after relation managers (table)
        return [
            SubnetIpCompositionChart::make([
                'record' => $this->record,
            ]),
        ];
    }
}

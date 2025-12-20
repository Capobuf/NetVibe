<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\Pages;

use App\Filament\Clusters\Discovery\Resources\DiscoveryEventResource;
use Filament\Resources\Pages\ListRecords;

class ListDiscoveryEvents extends ListRecords
{
    protected static string $resource = DiscoveryEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action - events are generated automatically by discovery
        ];
    }
}

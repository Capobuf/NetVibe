<?php

namespace App\Filament\Resources\ScanAgents\Pages;

use App\Filament\Resources\ScanAgents\ScanAgentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScanAgents extends ListRecords
{
    protected static string $resource = ScanAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

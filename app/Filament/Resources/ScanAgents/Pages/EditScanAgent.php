<?php

namespace App\Filament\Resources\ScanAgents\Pages;

use App\Filament\Resources\ScanAgents\ScanAgentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditScanAgent extends EditRecord
{
    protected static string $resource = ScanAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

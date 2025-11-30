<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages;

use App\Filament\Clusters\Discovery\Resources\ScanAgents\ScanAgentResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditScanAgent extends EditRecord
{
    protected static string $resource = ScanAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Scan Agent')
                ->modalDescription('This will permanently delete this scan agent and any associated scan history. This action cannot be undone.')
                ->color('danger'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\L2Domains\Pages;

use App\Filament\Clusters\Ipam\Resources\L2Domains\L2DomainResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditL2Domain extends EditRecord
{
    protected static string $resource = L2DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete L2 Domain')
                ->modalDescription('This will permanently delete this L2 domain and cannot be undone.')
                ->color('danger'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Vlans\Pages;

use App\Filament\Clusters\Ipam\Resources\Vlans\VlanResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditVlan extends EditRecord
{
    protected static string $resource = VlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete VLAN')
                ->modalDescription('This will permanently delete this VLAN and cannot be undone.')
                ->color('danger'),
        ];
    }
}

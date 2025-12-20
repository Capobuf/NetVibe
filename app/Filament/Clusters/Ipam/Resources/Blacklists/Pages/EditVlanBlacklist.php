<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Blacklists\Pages;

use App\Filament\Clusters\Ipam\Resources\Blacklists\VlanBlacklistResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditVlanBlacklist extends EditRecord
{
    protected static string $resource = VlanBlacklistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Remove VLAN blacklist entry')
                ->modalDescription('This will allow the VLAN to be imported again.')
                ->color('danger'),
        ];
    }
}

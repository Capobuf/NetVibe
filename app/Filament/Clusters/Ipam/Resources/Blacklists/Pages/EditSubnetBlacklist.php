<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Blacklists\Pages;

use App\Filament\Clusters\Ipam\Resources\Blacklists\SubnetBlacklistResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditSubnetBlacklist extends EditRecord
{
    protected static string $resource = SubnetBlacklistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Remove subnet blacklist entry')
                ->modalDescription('This will allow the subnet to be imported again.')
                ->color('danger'),
        ];
    }
}

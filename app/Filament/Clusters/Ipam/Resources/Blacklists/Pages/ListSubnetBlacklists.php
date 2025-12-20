<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Blacklists\Pages;

use App\Filament\Clusters\Ipam\Resources\Blacklists\SubnetBlacklistResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubnetBlacklists extends ListRecords
{
    protected static string $resource = SubnetBlacklistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New')
                ->icon('heroicon-o-plus')
                ->button()
                ->slideOver(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Blacklists\Pages;

use App\Filament\Clusters\Ipam\Resources\Blacklists\VlanBlacklistResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVlanBlacklists extends ListRecords
{
    protected static string $resource = VlanBlacklistResource::class;

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

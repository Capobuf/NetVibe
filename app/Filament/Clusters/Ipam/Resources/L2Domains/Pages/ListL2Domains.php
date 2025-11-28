<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\L2Domains\Pages;

use App\Filament\Clusters\Ipam\Resources\L2Domains\L2DomainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListL2Domains extends ListRecords
{
    protected static string $resource = L2DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->slideOver(),
        ];
    }
}

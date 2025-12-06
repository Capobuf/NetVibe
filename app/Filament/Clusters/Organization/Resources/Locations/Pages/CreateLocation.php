<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Organization\Resources\Locations\Pages;

use App\Filament\Clusters\Organization\Resources\Locations\LocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? parent::getRedirectUrl();
    }
}

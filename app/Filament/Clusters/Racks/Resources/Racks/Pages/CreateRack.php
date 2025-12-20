<?php

namespace App\Filament\Clusters\Racks\Resources\Racks\Pages;

use App\Filament\Clusters\Racks\Resources\Racks\RackResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRack extends CreateRecord
{
    protected static string $resource = RackResource::class;

    /**
     * Prefer returning to the previous URL (referrer) after creation when available.
     */
    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? parent::getRedirectUrl();
    }
}

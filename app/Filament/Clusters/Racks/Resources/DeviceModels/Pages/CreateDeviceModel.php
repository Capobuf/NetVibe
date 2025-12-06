<?php

namespace App\Filament\Clusters\Racks\Resources\DeviceModels\Pages;

use App\Filament\Clusters\Racks\Resources\DeviceModels\DeviceModelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDeviceModel extends CreateRecord
{
    protected static string $resource = DeviceModelResource::class;

    /**
     * Prefer returning to the previous URL (referrer) after creation when available.
     */
    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? parent::getRedirectUrl();
    }
}

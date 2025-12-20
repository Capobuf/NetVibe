<?php

namespace App\Filament\Resources\CableTypes\Pages;

use App\Filament\Resources\CableTypes\CableTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCableType extends CreateRecord
{
    protected static string $resource = CableTypeResource::class;

    /**
     * Prefer returning to the previous URL (referrer) after creation when available.
     */
    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? parent::getRedirectUrl();
    }
}

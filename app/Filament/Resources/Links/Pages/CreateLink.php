<?php

namespace App\Filament\Resources\Links\Pages;

use App\Filament\Resources\Links\LinkResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLink extends CreateRecord
{
    protected static string $resource = LinkResource::class;

    /**
     * Prefer returning to the previous URL (referrer) after creation when available.
     */
    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? parent::getRedirectUrl();
    }
}

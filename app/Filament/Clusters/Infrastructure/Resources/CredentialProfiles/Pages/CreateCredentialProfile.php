<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure\Resources\CredentialProfiles\Pages;

use App\Filament\Clusters\Infrastructure\Resources\CredentialProfiles\CredentialProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCredentialProfile extends CreateRecord
{
    protected static string $resource = CredentialProfileResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? static::getResource()::getUrl('index');
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure\Resources\CredentialProfiles\Pages;

use App\Filament\Clusters\Infrastructure\Resources\CredentialProfiles\CredentialProfileResource;
use Filament\Resources\Pages\ListRecords;

class ListCredentialProfiles extends ListRecords
{
    protected static string $resource = CredentialProfileResource::class;
}

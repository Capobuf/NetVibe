<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages;

use App\Filament\Clusters\Discovery\Resources\ScanAgents\ScanAgentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScanAgent extends CreateRecord
{
    protected static string $resource = ScanAgentResource::class;

    /**
     * Prefer returning to the previous URL (referrer) after creation when available.
     */
    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? parent::getRedirectUrl();
    }
}

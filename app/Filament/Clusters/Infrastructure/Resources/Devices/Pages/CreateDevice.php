<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure\Resources\Devices\Pages;

use App\Filament\Clusters\Infrastructure\Resources\Devices\DeviceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDevice extends CreateRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? static::getResource()::getUrl('index');
    }
}

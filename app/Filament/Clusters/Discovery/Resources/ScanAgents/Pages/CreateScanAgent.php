<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages;

use App\Filament\Clusters\Discovery\Resources\ScanAgents\ScanAgentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScanAgent extends CreateRecord
{
    protected static string $resource = ScanAgentResource::class;
}

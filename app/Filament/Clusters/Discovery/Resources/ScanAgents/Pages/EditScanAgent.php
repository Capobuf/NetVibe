<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages;

use App\Filament\Clusters\Discovery\Resources\ScanAgents\ScanAgentResource;
use Filament\Resources\Pages\EditRecord;

class EditScanAgent extends EditRecord
{
    protected static string $resource = ScanAgentResource::class;
}

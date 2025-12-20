<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\AgentJobs\Pages;

use App\Filament\Clusters\Discovery\Resources\AgentJobs\AgentJobResource;
use Filament\Resources\Pages\ListRecords;

class ListAgentJobs extends ListRecords
{
    protected static string $resource = AgentJobResource::class;

    protected function getHeaderActions(): array
    {
        // Job Center è solo di consultazione: nessuna azione di creazione diretta.
        return [];
    }
}


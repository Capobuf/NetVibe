<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\AgentJobs;

use App\Filament\Clusters\Discovery\Resources\AgentJobs\Pages\ListAgentJobs;
use App\Filament\Clusters\Discovery\Resources\AgentJobs\Tables\AgentJobsTable;
use App\Models\AgentJob;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class AgentJobResource extends Resource
{
    protected static ?string $model = AgentJob::class;

    /**
     * Il Job Center vive sotto il gruppo di navigazione "Discovery".
     */
    protected static string | \UnitEnum | null $navigationGroup = 'Discovery';

    protected static ?string $navigationLabel = 'Job Center';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return AgentJobsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentJobs::route('/'),
        ];
    }
}


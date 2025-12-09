<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\AgentJob;
use Filament\Actions\Exports\ExportColumn;

class AgentJobExporter extends BaseExporter
{
    /**
     * @return array<int, ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('Job ID'),
            ExportColumn::make('type')->label('Type'),
            ExportColumn::make('scope')->label('Scope'),
            ExportColumn::make('status')->label('Status'),
            ExportColumn::make('tenant.name')->label('Customer'),
            ExportColumn::make('device.hostname')->label('Device'),
            ExportColumn::make('scanAgent.name')->label('Agent'),
            ExportColumn::make('created_at')->label('Queued at'),
            ExportColumn::make('started_at')->label('Started at'),
            ExportColumn::make('finished_at')->label('Finished at'),
            ExportColumn::make('error_message')->label('Error'),
        ];
    }

    public static function getModel(): string
    {
        return AgentJob::class;
    }
}


<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\DiscoveryEvent;
use Filament\Actions\Exports\ExportColumn;

class DiscoveryEventExporter extends BaseExporter
{
    /**
     * @return array<int, ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('scanAgent.name')->label('Agent'),
            ExportColumn::make('created_at')->label('Time'),
            ExportColumn::make('ip_addr')->label('IP'),
            ExportColumn::make('mac_address_current')->label('MAC'),
            ExportColumn::make('hostname_current')->label('Hostname'),
            ExportColumn::make('conflict_type')->label('Conflict Type'),
            ExportColumn::make('status')->label('Status'),
        ];
    }

    public static function getModel(): string
    {
        return DiscoveryEvent::class;
    }
}


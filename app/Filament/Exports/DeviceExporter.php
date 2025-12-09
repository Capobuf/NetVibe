<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Device;
use Filament\Actions\Exports\ExportColumn;

class DeviceExporter extends BaseExporter
{
    /**
     * @return array<int, ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('hostname')->label('Hostname'),
            ExportColumn::make('ip_addr')->label('Management IP'),
            ExportColumn::make('deviceModel.name')->label('Model'),
            ExportColumn::make('customer.name')->label('Customer'),
            ExportColumn::make('location.name')->label('Location'),
            ExportColumn::make('auth_status')->label('Auth Status'),
            ExportColumn::make('last_seen_overall')->label('Last Seen'),
        ];
    }

    public static function getModel(): string
    {
        return Device::class;
    }
}


<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\IpAddress;
use Filament\Actions\Exports\ExportColumn;

class IpAddressExporter extends BaseExporter
{
    /**
     * @return array<int, ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('ip_str')->label('IP Address'),
            ExportColumn::make('hostname')->label('Hostname'),
            ExportColumn::make('mac_address')->label('MAC Address'),
            ExportColumn::make('owner')->label('Owner'),
            ExportColumn::make('state')->label('State'),
            ExportColumn::make('subnet.cidr')->label('Subnet'),
            ExportColumn::make('location.name')->label('Location'),
            ExportColumn::make('type')->label('Type'),
            ExportColumn::make('last_seen')->label('Last Seen'),
        ];
    }

    public static function getModel(): string
    {
        return IpAddress::class;
    }
}


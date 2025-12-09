<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Port;
use Filament\Actions\Exports\ExportColumn;

class PortExporter extends BaseExporter
{
    /**
     * @return array<int, ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('device.hostname')->label('Device'),
            ExportColumn::make('name')->label('Port'),
            ExportColumn::make('type')->label('Type'),
            ExportColumn::make('status')->label('Status'),
            ExportColumn::make('vlan_mode')->label('VLAN Mode'),
            ExportColumn::make('pvid')->label('PVID'),
            ExportColumn::make('tagged_vlans')->label('Tagged VLANs'),
            ExportColumn::make('mtu')->label('MTU'),
            ExportColumn::make('speed')->label('Speed'),
            ExportColumn::make('duplex')->label('Duplex'),
            ExportColumn::make('external_type')->label('Ext Type'),
            ExportColumn::make('description')->label('Description'),
        ];
    }

    public static function getModel(): string
    {
        return Port::class;
    }
}


<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Subnet;
use Filament\Actions\Exports\ExportColumn;

class SubnetExporter extends BaseExporter
{
    /**
     * @return array<int, ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('cidr')->label('CIDR'),
            ExportColumn::make('customer.name')->label('Customer'),
            ExportColumn::make('location.name')->label('Location'),
            ExportColumn::make('vlan.number')->label('VLAN'),
            ExportColumn::make('description')->label('Description'),
        ];
    }

    public static function getModel(): string
    {
        return Subnet::class;
    }
}


<?php

namespace App\Filament\Widgets;

use App\Models\Subnet;
use App\Services\IpConversionService;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TopUtilizedSubnetsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Subnet::query()
                    ->withCount('ipAddresses')
                    ->orderByDesc('ip_addresses_count')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('subnet')
                    ->label('Subnet')
                    ->formatStateUsing(fn ($record) => app(IpConversionService::class)->toCidr($record->subnet, $record->mask)),
                Tables\Columns\TextColumn::make('description'),
                Tables\Columns\TextColumn::make('ip_addresses_count')
                    ->label('IPs Used')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
            ])
            ->paginated(false);
    }
}

<?php

namespace App\Filament\Widgets;

use App\Models\Subnet;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestScanResultsWidget extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Subnet::query()->whereNotNull('last_discovery')->withCount('ipAddresses')->latest('last_discovery')->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Subnet Name'),
                Tables\Columns\TextColumn::make('cidr')->label('CIDR'),
                Tables\Columns\TextColumn::make('last_discovery')->label('Last Discovery')->since(),
                Tables\Columns\TextColumn::make('ip_addresses_count')->label('Found Hosts'),
            ])
            ->defaultSort('last_discovery', 'desc')
            ->paginated(false);
    }
}

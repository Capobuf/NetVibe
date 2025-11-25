<?php

namespace App\Filament\Widgets;

use App\Models\IpAddress;
use App\Services\IpConversionService;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestIpChangesWidget extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                IpAddress::query()->latest('updated_at')->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('ip_addr')
                    ->label('IP Address')
                    ->formatStateUsing(fn ($state) => app(IpConversionService::class)->fromDecimal($state)),
                Tables\Columns\TextColumn::make('hostname'),
                Tables\Columns\TextColumn::make('description'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since(),
            ])
            ->paginated(false);
    }
}

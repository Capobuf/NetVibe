<?php

namespace App\Filament\Resources\IpAddresses\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IpAddressesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ip_addr')
                    ->label('IP Address')
                    ->formatStateUsing(fn ($state) => app(\App\Services\IpConversionService::class)->fromDecimal($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('hostname')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('description')
                    ->searchable()
                    ->limit(30)
                    ->toggleable(),
                TextColumn::make('mac')
                    ->label('MAC Address')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('owner')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('state')
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        1 => 'danger',   // Offline
                        2 => 'success',  // Used
                        3 => 'primary',  // Reserved
                        4 => 'gray',     // DHCP
                        default => 'secondary',
                    })
                    ->formatStateUsing(fn ($state) => match($state) {
                        1 => 'Offline',
                        2 => 'Used',
                        3 => 'Reserved',
                        4 => 'DHCP',
                        default => 'Unknown',
                    })
                    ->sortable(),
                TextColumn::make('subnet.description')
                    ->label('Subnet')
                    ->toggleable(),
                TextColumn::make('location.name')
                    ->label('Location')
                    ->toggleable(),
                IconColumn::make('is_gateway')
                    ->label('Gateway')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('port')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen')
                    ->label('Last Seen')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

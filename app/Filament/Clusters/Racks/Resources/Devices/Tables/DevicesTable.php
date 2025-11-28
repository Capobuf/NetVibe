<?php

namespace App\Filament\Clusters\Racks\Resources\Devices\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hostname')
                    ->searchable(),
                TextColumn::make('ip_addr')
                    ->searchable(),
                TextColumn::make('type')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('description')
                    ->searchable(),
                // SNMP fields removed permanently
                TextColumn::make('rack_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('rack_start')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('rack_size')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('location_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

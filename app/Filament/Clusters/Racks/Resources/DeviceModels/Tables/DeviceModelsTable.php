<?php

namespace App\Filament\Clusters\Racks\Resources\DeviceModels\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeviceModelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('brand')
                    ->searchable()
                    ->sortable()
                    ->label('Brand'),

                TextColumn::make('model')
                    ->searchable()
                    ->sortable()
                    ->label('Model'),
                    
                TextColumn::make('manufacturer')
                    ->searchable()
                    ->sortable(),
                    
                TextColumn::make('description')
                    ->limit(50)
                    ->toggleable(),
                    
                TextColumn::make('devices_count')
                    ->counts('devices')
                    ->label('Devices')
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
            ])
            ->defaultSort('name');
    }
}

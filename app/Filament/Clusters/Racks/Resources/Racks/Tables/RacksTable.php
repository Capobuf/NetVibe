<?php

namespace App\Filament\Clusters\Racks\Resources\Racks\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use App\Models\Rack;
use App\Filament\Clusters\Racks\Resources\Racks\RackResource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RacksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Rack $record): string => RackResource::getUrl('view', ['record' => $record])),
                    
                TextColumn::make('customer.name')
                    ->searchable()
                    ->sortable()
                    ->label('Customer'),
                    
                TextColumn::make('location.name')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Default Site'),
                    
                TextColumn::make('units')
                    ->numeric()
                    ->sortable()
                    ->suffix(' U'),
                    
                TextColumn::make('devices_count')
                    ->counts('devices')
                    ->label('Devices'),
                    
                TextColumn::make('created_at')
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

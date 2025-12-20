<?php

namespace App\Filament\Resources\Links\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Table;

class LinksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('portA.device.hostname')
                    ->label('Port A Device')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state, $record) => optional($record->portA->device)->hostname . ': ' . optional($record->portA)->name),

                \Filament\Tables\Columns\TextColumn::make('portB.device.hostname')
                    ->label('Port B Device')
                    ->formatStateUsing(fn ($state, $record) => optional($record->portB->device)->hostname . ': ' . optional($record->portB)->name),

                \Filament\Tables\Columns\TextColumn::make('cableType.name')
                    ->label('Cable')
                    ->formatStateUsing(fn ($state, $record) => ($record->cableType?->name) ? '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded" style="background: '.($record->cableType->color ?? '#000').'"></span>' . $record->cableType->name . '</span>' : '')
                    ->html(),

                \Filament\Tables\Columns\TextColumn::make('length')
                    ->label('Length')
                    ->suffix('m')
                    ->sortable(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('cable_type_id')
                    ->label('Cable Type')
                    ->relationship('cableType', 'name'),

                \Filament\Tables\Filters\SelectFilter::make('device')
                    ->label('Device')
                    ->options(fn () => \App\Models\Device::pluck('hostname','id')->toArray())
                    ->query(fn (Builder $query, $value) => $query->whereHas('portA.device', fn ($q) => $q->where('id', $value))->orWhereHas('portB.device', fn ($q) => $q->where('id', $value))),
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

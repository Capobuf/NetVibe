<?php

namespace App\Filament\Resources\Devices\Tables;

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
                TextColumn::make('snmp_community')
                    ->searchable(),
                TextColumn::make('snmp_version'),
                TextColumn::make('snmp_port')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('snmp_timeout')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('snmp_queries')
                    ->searchable(),
                TextColumn::make('snmp_v3_sec_level'),
                TextColumn::make('snmp_v3_auth_protocol'),
                TextColumn::make('snmp_v3_auth_pass')
                    ->searchable(),
                TextColumn::make('snmp_v3_priv_protocol'),
                TextColumn::make('snmp_v3_priv_pass')
                    ->searchable(),
                TextColumn::make('snmp_v3_ctx_name')
                    ->searchable(),
                TextColumn::make('snmp_v3_ctx_engine_id')
                    ->searchable(),
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
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

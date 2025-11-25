<?php

namespace App\Filament\Resources\Subnets\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubnetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subnet')
                    ->searchable(),
                TextColumn::make('mask')
                    ->searchable(),
                TextColumn::make('section_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('linked_subnet_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('firewall_address_object')
                    ->searchable(),
                TextColumn::make('vrf_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('master_subnet_id')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('allow_requests')
                    ->boolean(),
                TextColumn::make('vlan_id')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('show_name')
                    ->boolean(),
                TextColumn::make('device_id')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('ping_subnet')
                    ->boolean(),
                IconColumn::make('discover_subnet')
                    ->boolean(),
                IconColumn::make('resolve_dns')
                    ->boolean(),
                IconColumn::make('dns_recursive')
                    ->boolean(),
                IconColumn::make('dns_records')
                    ->boolean(),
                TextColumn::make('nameserver_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('scan_agent_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('customer_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('location_id')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_folder')
                    ->boolean(),
                IconColumn::make('is_full')
                    ->boolean(),
                IconColumn::make('is_pool')
                    ->boolean(),
                TextColumn::make('state')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('threshold')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_scan')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_discovery')
                    ->dateTime()
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

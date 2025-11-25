<?php

namespace App\Filament\Resources\Subnets\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SubnetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('subnet')
                    ->default(null),
                TextInput::make('mask')
                    ->default(null),
                Select::make('section_id')
                    ->relationship('section', 'name')
                    ->searchable()
                    ->preload()
                    ->default(null),
                Textarea::make('description')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('linked_subnet_id')
                    ->numeric()
                    ->default(null),
                TextInput::make('firewall_address_object')
                    ->default(null),
                Select::make('vrf_id')
                    ->relationship('vrf', 'name')
                    ->searchable()
                    ->preload()
                    ->default(null),
                Select::make('master_subnet_id')
                    ->label('Parent subnet')
                    ->relationship('parentSubnet', 'subnet')
                    ->searchable()
                    ->preload()
                    ->default(0)
                    ->required(),
                Toggle::make('allow_requests')
                    ->required(),
                Select::make('vlan_id')
                    ->options(function () {
                        return \App\Models\Vlan::query()
                            ->orderBy('number')
                            ->get()
                            ->mapWithKeys(fn($v) => [$v->id => ($v->number ? ($v->number . ' - ') : '') . $v->name])
                            ->toArray();
                    })
                    ->searchable()
                    ->preload()
                    ->default(null),
                Toggle::make('show_name')
                    ->required(),
                Select::make('device_id')
                    ->relationship('device', 'hostname')
                    ->searchable()
                    ->preload()
                    ->default(null),
                Textarea::make('permissions')
                    ->default(null)
                    ->columnSpanFull(),
                Toggle::make('ping_subnet')
                    ->required(),
                Toggle::make('discover_subnet')
                    ->required(),
                Toggle::make('resolve_dns')
                    ->required(),
                Toggle::make('dns_recursive')
                    ->required(),
                Toggle::make('dns_records')
                    ->required(),
                TextInput::make('nameserver_id')
                    ->numeric()
                    ->default(0),
                Select::make('scan_agent_id')
                    ->relationship('scanAgent', 'name')
                    ->searchable()
                    ->preload()
                    ->default(null),
                Select::make('customer_id')
                    ->relationship('customer', 'title')
                    ->searchable()
                    ->preload()
                    ->default(null),
                Select::make('location_id')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload()
                    ->default(null),
                Toggle::make('is_folder')
                    ->required(),
                Toggle::make('is_full')
                    ->required(),
                Toggle::make('is_pool')
                    ->required(),
                TextInput::make('state')
                    ->numeric()
                    ->default(2),
                TextInput::make('threshold')
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('last_scan'),
                DateTimePicker::make('last_discovery'),
            ]);
    }
}

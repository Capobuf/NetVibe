<?php

namespace App\Filament\Resources\Devices\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class DeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('hostname')
                    ->default(null),
                TextInput::make('ip_addr')
                    ->default(null),
                TextInput::make('type')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('description')
                    ->default(null),
                Textarea::make('sections')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('snmp_community')
                    ->default(null),
                TextInput::make('snmp_version')
                    ->required()
                    ->default('0'),
                TextInput::make('snmp_port')
                    ->required()
                    ->numeric()
                    ->default(161),
                TextInput::make('snmp_timeout')
                    ->required()
                    ->numeric()
                    ->default(1000),
                TextInput::make('snmp_queries')
                    ->default(null),
                TextInput::make('snmp_v3_sec_level')
                    ->required()
                    ->default('none'),
                TextInput::make('snmp_v3_auth_protocol')
                    ->required()
                    ->default('none'),
                TextInput::make('snmp_v3_auth_pass')
                    ->default(null),
                TextInput::make('snmp_v3_priv_protocol')
                    ->required()
                    ->default('none'),
                TextInput::make('snmp_v3_priv_pass')
                    ->default(null),
                TextInput::make('snmp_v3_ctx_name')
                    ->default(null),
                TextInput::make('snmp_v3_ctx_engine_id')
                    ->default(null),
                TextInput::make('rack_id')
                    ->numeric()
                    ->default(null),
                TextInput::make('rack_start')
                    ->numeric()
                    ->default(null),
                TextInput::make('rack_size')
                    ->numeric()
                    ->default(null),
                Select::make('location_id')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload()
                    ->default(null),
            ]);
    }
}

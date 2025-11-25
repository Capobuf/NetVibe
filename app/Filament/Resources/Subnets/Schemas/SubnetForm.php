<?php

namespace App\Filament\Resources\Subnets\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
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
                TextInput::make('section_id')
                    ->numeric()
                    ->default(null),
                Textarea::make('description')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('linked_subnet_id')
                    ->numeric()
                    ->default(null),
                TextInput::make('firewall_address_object')
                    ->default(null),
                TextInput::make('vrf_id')
                    ->numeric()
                    ->default(null),
                TextInput::make('master_subnet_id')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('allow_requests')
                    ->required(),
                TextInput::make('vlan_id')
                    ->numeric()
                    ->default(null),
                Toggle::make('show_name')
                    ->required(),
                TextInput::make('device_id')
                    ->numeric()
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
                TextInput::make('scan_agent_id')
                    ->numeric()
                    ->default(null),
                TextInput::make('customer_id')
                    ->numeric()
                    ->default(null),
                TextInput::make('location_id')
                    ->numeric()
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

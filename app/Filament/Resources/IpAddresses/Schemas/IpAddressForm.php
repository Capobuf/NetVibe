<?php

namespace App\Filament\Resources\IpAddresses\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class IpAddressForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('subnet_id')
                    ->relationship('subnet', 'subnet')
                    ->searchable()
                    ->preload()
                    ->default(null),
                TextInput::make('ip_addr')
                    ->required(),
                Toggle::make('is_gateway')
                    ->required(),
                TextInput::make('description')
                    ->default(null),
                TextInput::make('hostname')
                    ->default(null),
                TextInput::make('mac')
                    ->default(null),
                TextInput::make('owner')
                    ->default(null),
                TextInput::make('state')
                    ->numeric()
                    ->default(2),
                Select::make('switch_id')
                    ->relationship('switchDevice', 'hostname')
                    ->label('Switch')
                    ->searchable()
                    ->preload()
                    ->default(null),
                Select::make('location_id')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload()
                    ->default(null),
                TextInput::make('port')
                    ->default(null),
                Textarea::make('note')
                    ->default(null)
                    ->columnSpanFull(),
                DateTimePicker::make('last_seen'),
                Toggle::make('exclude_ping')
                    ->required(),
                Toggle::make('ptr_ignore')
                    ->required(),
                TextInput::make('ptr')
                    ->numeric()
                    ->default(0),
                TextInput::make('firewall_address_object')
                    ->default(null),
                Select::make('customer_id')
                    ->relationship('customer', 'title')
                    ->searchable()
                    ->preload()
                    ->default(null),
            ]);
    }
}

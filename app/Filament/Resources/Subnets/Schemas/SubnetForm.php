<?php

namespace App\Filament\Resources\Subnets\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Group;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DateTimePicker;

class SubnetForm
{
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make()
                    ->schema([
                        Section::make('General')
                            ->schema([
                                Group::make()->schema([
                                    TextInput::make('subnet')
                                        ->label('Subnet')
                                        ->required(),

                                    TextInput::make('mask')
                                        ->label('Mask')
                                        ->numeric()
                                        ->required(),
                                ]),

                                Select::make('section_id')
                                    ->label('Section')
                                    ->relationship('section', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),

                                Textarea::make('description')
                                    ->label('Description')
                                    ->columnSpanFull(),

                                Select::make('master_subnet_id')
                                    ->label('Parent subnet')
                                    ->relationship('parentSubnet', 'subnet')
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),

                                Select::make('vlan_id')
                                    ->label('VLAN')
                                    ->options(function () {
                                        return \App\Models\Vlan::query()
                                            ->orderBy('number')
                                            ->get()
                                            ->mapWithKeys(fn($v) => [$v->id => ($v->number ? ($v->number . ' - ') : '') . $v->name])
                                            ->toArray();
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),

                                Select::make('vrf_id')
                                    ->label('VRF')
                                    ->relationship('vrf', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),

                                Select::make('device_id')
                                    ->label('Device')
                                    ->relationship('device', 'hostname')
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),

                                Select::make('customer_id')
                                    ->label('Customer')
                                    ->relationship('customer', 'title')
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),

                                Select::make('location_id')
                                    ->label('Location')
                                    ->relationship('location', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),
                            ])
                            ->columns(1),

                        Section::make('Flags & Limits')
                            ->schema([
                                Toggle::make('allow_requests')
                                    ->label('Allow Requests'),

                                Toggle::make('show_name')
                                    ->label('Show Names'),

                                Toggle::make('is_folder')
                                    ->label('Folder'),

                                Toggle::make('is_full')
                                    ->label('Full'),

                                Toggle::make('is_pool')
                                    ->label('Pool'),

                                TextInput::make('threshold')
                                    ->label('Threshold')
                                    ->numeric(),

                                TextInput::make('state')
                                    ->label('State')
                                    ->numeric(),
                            ])
                            ->columns(1),
                    ])
                    ->columnSpanFull(),

                Section::make('Discovery Settings')
                    ->schema([
                        Select::make('scan_interval')
                            ->label('Scan Interval')
                            ->options([
                                null => 'Default Globale',
                                5 => '5 Min',
                                60 => '1 Ora',
                                1440 => '24 Ore',
                                0 => 'Disabled',
                            ])
                            ->nullable(),

                        Select::make('scan_agent_id')
                            ->label('Scan Agent')
                            ->relationship('scanAgent', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),

                        Group::make()->schema([
                            Toggle::make('ping_subnet')
                                ->label('Enable Ping Scan'),

                            Toggle::make('discover_subnet')
                                ->label('Discovery'),
                        ]),

                        Group::make()->schema([
                            Toggle::make('resolve_dns')
                                ->label('Resolve DNS'),

                            Toggle::make('dns_recursive')
                                ->label('DNS Recursive'),

                            Toggle::make('dns_records')
                                ->label('DNS Records'),
                        ]),

                        TextInput::make('nameserver_id')
                            ->label('Nameserver')
                            ->numeric(),

                        DateTimePicker::make('last_scan')
                            ->label('Last Scan'),

                        DateTimePicker::make('last_discovery')
                            ->label('Last Discovery'),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}

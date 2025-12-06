<?php

namespace App\Filament\Clusters\Racks\Resources\DeviceModels\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\ColorPicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use App\Enums\DeviceType;

class DeviceModelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('brand')
                    ->required()
                    ->maxLength(255)
                    ->label('Brand')
                    ->placeholder('e.g., Cisco, MikroTik, Ubiquiti'),

                TextInput::make('model')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->label('Model')
                    ->placeholder('e.g., 2960, CCR1036'),

                Select::make('type')
                    ->options(DeviceType::class)
                    ->required()
                    ->default(DeviceType::Switch->value),
                    
                TextInput::make('u_size')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->maxValue(48)
                    ->label('Default Size (U)'),
                    
                TextInput::make('ethernet_ports')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->label('Ethernet ports'),

                TextInput::make('sfp_ports')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->label('SFP ports'),

                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull()
                    ->placeholder('Additional notes about this model'),

                Section::make('Visual Layout Designer')
                    ->columnSpanFull()
                    ->schema([
                        // Outer repeater: physical rows (horizontal rows on the device)
                        Repeater::make('visual_layout')
                            ->label('Physical Rows')
                            ->defaultItems(1)
                            ->reorderable()
                            ->cloneable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->schema([
                                // Inner repeater: banks/groups placed horizontally inside the row
                                Repeater::make('banks')
                                    ->label('Port Groups')
                                    ->grid(2) // render groups side-by-side to simulate physical layout
                                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'Group')
                                    ->schema([
                                        TextInput::make('name')
                                            ->required()
                                            ->placeholder('es. Eth 1-12'),

                                        Grid::make(3)
                                            ->schema([
                                                TextInput::make('start_port')
                                                    ->numeric()
                                                    ->required()
                                                    ->label('Start #'),

                                                TextInput::make('port_count')
                                                    ->numeric()
                                                    ->required()
                                                    ->label('Count'),

                                                ColorPicker::make('color')
                                                    ->label('Color'),
                                            ])
                                    ])
                                    ->collapsible()
                                    ->defaultItems(1),
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

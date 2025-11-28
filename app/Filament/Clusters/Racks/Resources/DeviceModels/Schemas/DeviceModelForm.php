<?php

namespace App\Filament\Clusters\Racks\Resources\DeviceModels\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
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
            ]);
    }
}

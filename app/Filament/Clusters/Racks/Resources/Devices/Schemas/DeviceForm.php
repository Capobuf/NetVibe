<?php

namespace App\Filament\Clusters\Racks\Resources\Devices\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
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
                // SNMP-related fields removed completely (sensitive / deprecated)
                Select::make('device_model_id')
                    ->relationship('deviceModel', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        TextInput::make('brand')->required(),
                        TextInput::make('model')->required(),
                    ])
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set) {
                        if ($state) {
                            $model = \App\Models\DeviceModel::find($state);
                            if ($model) {
                                $set('rack_size', $model->default_rack_size);
                            }
                        }
                    }),

                Select::make('rack_id')
                    ->relationship('rack', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Rack')
                    ->default(request()->query('rack_id'))
                    ->options(function (Get $get) {
                        $query = \App\Models\Rack::query();
                        if ($locationId = $get('location_id')) {
                            $query->where('location_id', $locationId);
                        }
                        return $query->pluck('name', 'id');
                    }),

                TextInput::make('rack_position')
                    ->default(request()->query('rack_position'))
                    ->numeric()
                    ->label('Position (U)')
                    ->minValue(1)
                    ->maxValue(52),

                TextInput::make('rack_size')
                    ->numeric()
                    ->default(1)
                    ->label('Size (U)')
                    ->minValue(1),
                Select::make('location_id')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload()
                    ->default(null),
            ]);
    }
}

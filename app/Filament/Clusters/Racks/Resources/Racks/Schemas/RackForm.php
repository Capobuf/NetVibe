<?php

namespace App\Filament\Clusters\Racks\Resources\Racks\Schemas;

use App\Models\Location;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Schemas\Schema;

class RackForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Rack Name'),
                    
                TextInput::make('model')
                    ->maxLength(255)
                    ->label('Physical Model'),
                    
                TextInput::make('units')
                    ->required()
                    ->numeric()
                    ->default(42)
                    ->minValue(1)
                    ->maxValue(100)
                    ->label('Height (Units)'),
                    
                Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn (Select $component) => $component->getContainer()->getComponent('location_id')?->state(null)),
                    
                Select::make('location_id')
                    ->label('Location')
                    ->options(fn (Get $get) => Location::query()
                        ->where('customer_id', $get('customer_id'))
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->disabled(fn (Get $get) => ! $get('customer_id'))
                    ->key('location_id'),
                    
                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}

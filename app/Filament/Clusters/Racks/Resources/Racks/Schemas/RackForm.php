<?php

namespace App\Filament\Clusters\Racks\Resources\Racks\Schemas;

use App\Models\Location;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                    // If a tenant is selected in session, use it as the default and hide the field
                    ->default(fn () => session('tenant_id'))
                    ->hidden(fn () => ! blank(session('tenant_id')))
                    // Ensure the value is persisted even when hidden
                    ->dehydratedWhenHidden()
                    ->afterStateUpdated(fn ($set) => $set('location_id', null)),
                    
                Select::make('location_id')
                    ->label('Location')
                    // Show only locations for the selected customer
                    ->options(fn ($get) => Location::query()
                        ->where('customer_id', $get('customer_id'))
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    // Make location optional
                    ->nullable()
                    // Auto-select when the selected customer has exactly one location
                    ->default(function ($get) {
                        $customerId = $get('customer_id');
                        if (! $customerId) {
                            return null;
                        }
                        $locations = Location::where('customer_id', $customerId)->pluck('id');
                        return $locations->count() === 1 ? $locations->first() : null;
                    })
                    ->live()
                    // Keep disabled until a customer is chosen
                    ->disabled(fn ($get) => ! $get('customer_id'))
                    ->key('location_id'),
                    
                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}

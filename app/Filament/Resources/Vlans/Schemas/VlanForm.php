<?php

namespace App\Filament\Resources\Vlans\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class VlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('domain_id')
                    ->relationship('l2Domain', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->default(1),
                TextInput::make('name')
                    ->required(),
                TextInput::make('number')
                    ->numeric()
                    ->default(null),
                Textarea::make('description')
                    ->default(null)
                    ->columnSpanFull(),
                Select::make('customer_id')
                    ->relationship('customer', 'title')
                    ->searchable()
                    ->preload()
                    ->default(null),
            ]);
    }
}

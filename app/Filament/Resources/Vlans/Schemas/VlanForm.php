<?php

namespace App\Filament\Resources\Vlans\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class VlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('domain_id')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('name')
                    ->required(),
                TextInput::make('number')
                    ->numeric()
                    ->default(null),
                Textarea::make('description')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('customer_id')
                    ->numeric()
                    ->default(null),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                TextInput::make('address')
                    ->default(null),
                TextInput::make('postcode')
                    ->default(null),
                TextInput::make('city')
                    ->default(null),
                TextInput::make('state')
                    ->default(null),
                TextInput::make('lat')
                    ->default(null),
                TextInput::make('long')
                    ->default(null),
                Textarea::make('contact_person')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('contact_phone')
                    ->tel()
                    ->default(null),
                TextInput::make('contact_mail')
                    ->default(null),
                Textarea::make('note')
                    ->default(null)
                    ->columnSpanFull(),
                Select::make('status')
                    ->options(['Active' => 'Active', 'Reserved' => 'Reserved', 'Inactive' => 'Inactive'])
                    ->default('Active')
                    ->required(),
            ]);
    }
}

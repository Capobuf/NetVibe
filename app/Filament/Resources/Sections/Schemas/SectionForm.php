<?php

namespace App\Filament\Resources\Sections\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Textarea::make('description')
                    ->default(null)
                    ->columnSpanFull(),
                Select::make('master_section_id')
                    ->label('Parent section')
                    ->relationship('parentSection', 'name')
                    ->searchable()
                    ->preload()
                    ->default(0),
                Textarea::make('permissions')
                    ->default(null)
                    ->columnSpanFull(),
                Toggle::make('strict_mode')
                    ->required(),
                TextInput::make('subnet_ordering')
                    ->default(null),
                TextInput::make('order')
                    ->numeric()
                    ->default(null),
                Toggle::make('show_subnet')
                    ->required(),
                Toggle::make('show_vlan')
                    ->required(),
                Toggle::make('show_vrf')
                    ->required(),
                Toggle::make('show_supernet_only')
                    ->required(),
                TextInput::make('dns')
                    ->default(null),
            ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\ScanAgents\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class ScanAgentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('ip_address')
                ->ipv4()
                ->nullable()
                ->helperText('Leave empty for Localhost'),

            Textarea::make('description')
                ->rows(3)
                ->columnSpanFull(),

            Toggle::make('active')
                ->default(true),

            Select::make('customer_id')
                ->relationship('customer', 'name')
                ->searchable()
                ->preload()
                ->label('Dedicated to Customer')
                ->helperText('Leave empty if this is a Global/Shared agent (e.g. Localhost)')
        ]);
    }
}

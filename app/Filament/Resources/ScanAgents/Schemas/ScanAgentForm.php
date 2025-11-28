<?php

namespace App\Filament\Resources\ScanAgents\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\BelongsToSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ScanAgentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->default(null),
                BelongsToSelect::make('customer_id')
                    ->relationship('customer', 'title')
                    ->nullable()
                    ->searchable(),
                Textarea::make('description')
                    ->default(null)
                    ->columnSpanFull(),
                Select::make('type')
                    ->options(['direct' => 'Direct', 'api' => 'Api', 'mysql' => 'Mysql'])
                    ->default('direct')
                    ->required(),
                TextInput::make('code')
                    ->default(null),
                DateTimePicker::make('last_access'),
            ]);
    }
}

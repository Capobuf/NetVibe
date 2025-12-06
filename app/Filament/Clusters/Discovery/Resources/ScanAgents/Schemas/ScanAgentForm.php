<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\ScanAgents\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Placeholder;

class ScanAgentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Agent Details')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),

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
                        ->default(fn () => session('tenant_id'))
                        ->hidden(fn () => ! blank(session('tenant_id')))
                        ->dehydratedWhenHidden(),

                    Select::make('log_level')
                        ->label('Log Level')
                        ->options([
                            'INFO' => 'Info',
                            'ERROR' => 'Error',
                            'CRITICAL' => 'Critical',
                        ])
                        ->default('ERROR')
                        ->required()
                        ->helperText('Minimum log level to send from this agent'),
                    
                    Placeholder::make('api_key_guide')
                        ->label('API Key')
                        ->content('The API Key can be generated in the "Edit" page after creating the agent.')
                        ->columnSpanFull(),
                ])
        ]);
    }
}

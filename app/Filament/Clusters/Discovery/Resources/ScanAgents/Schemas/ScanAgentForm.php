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
                        ->label('Customer')
                        ->helperText('Required. NetVibe uses 1 Remote Agent per Customer.')
                        ->default(fn () => session('tenant_id'))
                        ->hidden(fn () => ! blank(session('tenant_id')))
                        ->dehydratedWhenHidden()
                        ->required(fn () => blank(session('tenant_id')))
                        ->rules([
                            function ($get) {
                                return function (string $attribute, $value, callable $fail) use ($get) {
                                    $active = $get('active') ?? true;
                                    if (blank($value)) {
                                        $fail('Il campo Cliente è obbligatorio.');
                                        return;
                                    }
                                    if (! $active) {
                                        return;
                                    }

                                    $exists = \App\Models\ScanAgent::query()
                                        ->where('active', true)
                                        ->where('customer_id', $value)
                                        ->when(request()->route('record'), fn ($q, $id) => $q->where('id', '!=', $id))
                                        ->exists();

                                    if ($exists) {
                                        $fail('Esiste già un Remote Agent attivo per questo cliente. Disattiva l’altro agent prima di attivare questo.');
                                    }
                                };
                            },
                        ]),

                    Select::make('log_level')
                        ->label('Log Level')
                        ->options([
                            'DEBUG' => 'Debug',
                            'INFO' => 'Info',
                            'WARNING' => 'Warning',
                            'ERROR' => 'Error',
                            'CRITICAL' => 'Critical',
                        ])
                        ->default('INFO')
                        ->required()
                        ->helperText('Minimum log level to ship and apply at runtime'),
                    
                    Placeholder::make('api_key_guide')
                        ->label('API Key')
                        ->content('The API Key can be generated in the "Edit" page after creating the agent.')
                        ->columnSpanFull(),
                ])
            ,
            // Tuning tab for agent-specific settings
            Section::make('Tuning')
                ->schema([
                    TextInput::make('polling_interval_minutes')
                        ->label('Polling interval')
                        ->numeric()
                        ->suffix('mins')
                        ->helperText('Override the default agent polling interval for this agent'),

                    Textarea::make('settings')
                        ->label('Raw settings (JSON)')
                        ->rows(8)
                        ->helperText('Advanced: free-form JSON settings inherited by this agent')
                        ->columnSpanFull(),
                ])
        ]);
    }
}

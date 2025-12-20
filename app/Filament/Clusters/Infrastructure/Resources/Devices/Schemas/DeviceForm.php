<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure\Resources\Devices\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;

class DeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Device Tabs')->tabs([
                Tab::make('General')->schema([
                    Toggle::make('is_monitored')->label('Is monitored')->helperText('If disabled, device will not be polled by agents.'),
                    TextInput::make('hostname')
                        ->required(fn ($get) => ! $get('is_monitored'))
                        ->disabled(fn ($get, $record) => $record && $record->exists && $get('is_monitored'))
                        ->maxLength(255)
                        ->helperText('When monitoring is enabled, hostname will be fetched from the device identity.'),
                    Select::make('device_model_id')
                        ->relationship('deviceModel', 'model')
                        ->preload()
                        ->required(fn ($get) => ! ($get('is_monitored') && (
                            $get('auth_driver') === 'mikrotik_rest'
                            && (filled($get('credential_profile_id')) || filled($get('auth_username')) || filled($get('auth_password')) || filled($get('auth_extra')))
                        )))
                        ->disabled(fn ($get, $record) => $record && $record->exists || (
                            $get('auth_driver') === 'mikrotik_rest'
                            && (filled($get('credential_profile_id')) || filled($get('auth_username')) || filled($get('auth_password')) || filled($get('auth_extra')))
                        ))
                        ->helperText('Locked when Mikrotik API credentials are provided — inventory will be pulled from the device. Required only if not auto-discovered.'),
                    TextInput::make('serial_number')->nullable(),
                    Textarea::make('description')->rows(3)->columnSpanFull(),
                ]),

                Tab::make('Connectivity')->schema([
                    Select::make('credential_profile_id')->relationship('credentialProfile', 'name')->label('Credential Profile')->nullable()->searchable()->preload(),
                    Placeholder::make('manual_auth_note')->content('When a Credential Profile is not selected you may enter manual authentication details below.'),
                ]),

                Tab::make('Authentication')->schema([
                    Select::make('auth_driver')->label('Auth Driver')->options(['mikrotik_rest' => 'MikroTik REST', 'ssh' => 'SSH'])->default('mikrotik_rest'),
                    TextInput::make('auth_username')->label('Username')->visible(fn ($get) => empty($get('credential_profile_id'))),
                    TextInput::make('auth_password')->label('Password')->password()->visible(fn ($get) => empty($get('credential_profile_id'))),
                    TextInput::make('auth_extra')->label('Auth JSON')->visible(fn ($get) => empty($get('credential_profile_id')))->helperText('Optional extra auth JSON for manual mode.'),
                ]),

                Tab::make('Inventory')->schema([
                    TextInput::make('brand')->label('Brand')->disabled()->readOnly(),
                    TextInput::make('model')->label('Model')->disabled()->readOnly(),
                    TextInput::make('ip_addr')
                        ->label('IP Address')
                        ->nullable()
                        ->helperText('IPv4 address used by the agent to reach the device (recommended when monitoring)'),
                ]),
            ]),
        ]);
    }
}

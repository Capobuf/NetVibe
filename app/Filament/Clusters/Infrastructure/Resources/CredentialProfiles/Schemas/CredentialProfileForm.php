<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure\Resources\CredentialProfiles\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class CredentialProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('type')->options(['ssh' => 'SSH', 'snmp' => 'SNMP', 'http' => 'HTTP'])->default('ssh')->required(),
            TextInput::make('username')->nullable(),
            TextInput::make('auth_password')->password()->nullable()->helperText('Sensitive field (stored encrypted)'),
            Textarea::make('auth_credentials')->rows(5)->nullable()->helperText('Optional JSON blob with additional credentials (stored encrypted).'),
            Textarea::make('description')->rows(3)->nullable()->columnSpanFull(),
        ]);
    }
}

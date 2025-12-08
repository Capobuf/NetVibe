<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure\Resources\CredentialProfiles;

use App\Filament\Clusters\Infrastructure\InfrastructureCluster;
use App\Filament\Clusters\Infrastructure\Resources\CredentialProfiles\Pages\ListCredentialProfiles;
use App\Filament\Clusters\Infrastructure\Resources\CredentialProfiles\Pages\CreateCredentialProfile;
use App\Filament\Clusters\Infrastructure\Resources\CredentialProfiles\Pages\EditCredentialProfile;
use App\Filament\Clusters\Infrastructure\Resources\CredentialProfiles\Schemas\CredentialProfileForm;
use App\Filament\Clusters\Infrastructure\Resources\CredentialProfiles\Tables\CredentialProfilesTable;
use App\Models\CredentialProfile;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use BackedEnum;

class CredentialProfileResource extends Resource
{
    protected static ?string $model = CredentialProfile::class;

    protected static string | \UnitEnum | null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return CredentialProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CredentialProfilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCredentialProfiles::route('/'),
            'create' => CreateCredentialProfile::route('/create'),
            'edit' => EditCredentialProfile::route('/{record}/edit'),
        ];
    }
}

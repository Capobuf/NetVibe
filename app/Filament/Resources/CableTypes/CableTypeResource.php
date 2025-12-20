<?php

namespace App\Filament\Resources\CableTypes;

use App\Filament\Clusters\Infrastructure\InfrastructureCluster;
use App\Filament\Resources\CableTypes\Pages\CreateCableType;
use App\Filament\Resources\CableTypes\Pages\EditCableType;
use App\Filament\Resources\CableTypes\Pages\ListCableTypes;
use App\Filament\Resources\CableTypes\Schemas\CableTypeForm;
use App\Filament\Resources\CableTypes\Tables\CableTypesTable;
use App\Models\CableType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CableTypeResource extends Resource
{
    protected static ?string $model = CableType::class;

    // Remove resource-level icon so group icon is used instead
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $recordTitleAttribute = 'name';

    // Detached from cluster to be shown under the 'Infrastructure' navigation group
    // protected static ?string $cluster = InfrastructureCluster::class;

    // Ensure this resource appears under the Infrastructure dropdown
    protected static string | \UnitEnum | null $navigationGroup = 'Infrastructure';

    public static function form(Schema $schema): Schema
    {
        return CableTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CableTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCableTypes::route('/'),
            'create' => CreateCableType::route('/create'),
            'edit' => EditCableType::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}

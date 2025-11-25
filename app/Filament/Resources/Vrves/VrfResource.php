<?php

namespace App\Filament\Resources\Vrves;

use App\Filament\Resources\Vrves\Pages\CreateVrf;
use App\Filament\Resources\Vrves\Pages\EditVrf;
use App\Filament\Resources\Vrves\Pages\ListVrves;
use App\Filament\Resources\Vrves\Schemas\VrfForm;
use App\Filament\Resources\Vrves\Tables\VrvesTable;
use App\Models\Vrf;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;
use BackedEnum;

class VrfResource extends Resource
{
    protected static ?string $model = Vrf::class;

    protected static ?string $modelLabel = 'VRF';
    protected static ?string $pluralModelLabel = 'VRFs';
    protected static ?string $navigationLabel = 'VRFs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static UnitEnum|string|null $navigationGroup = 'Network Infrastructure';

    public static function form(Schema $schema): Schema
    {
        return VrfForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VrvesTable::configure($table);
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
            'index' => ListVrves::route('/'),
            'create' => CreateVrf::route('/create'),
            'edit' => EditVrf::route('/{record}/edit'),
        ];
    }
}

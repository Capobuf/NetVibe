<?php

namespace App\Filament\Resources\Vlans;

use App\Filament\Resources\Vlans\Pages\CreateVlan;
use App\Filament\Resources\Vlans\Pages\EditVlan;
use App\Filament\Resources\Vlans\Pages\ListVlans;
use App\Filament\Resources\Vlans\Schemas\VlanForm;
use App\Filament\Resources\Vlans\Tables\VlansTable;
use App\Models\Vlan;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;
use BackedEnum;

class VlanResource extends Resource
{
    protected static ?string $model = Vlan::class;

    protected static ?string $modelLabel = 'VLAN';
    protected static ?string $pluralModelLabel = 'VLANs';
    protected static ?string $navigationLabel = 'VLANs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static UnitEnum|string|null $navigationGroup = 'Network Infrastructure';

    public static function form(Schema $schema): Schema
    {
        return VlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VlansTable::configure($table);
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
            'index' => ListVlans::route('/'),
            'create' => CreateVlan::route('/create'),
            'edit' => EditVlan::route('/{record}/edit'),
        ];
    }
}

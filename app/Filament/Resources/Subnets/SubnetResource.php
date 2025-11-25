<?php

namespace App\Filament\Resources\Subnets;

use App\Filament\Resources\Subnets\Pages\CreateSubnet;
use App\Filament\Resources\Subnets\Pages\EditSubnet;
use App\Filament\Resources\Subnets\Pages\ListSubnets;
use App\Filament\Resources\Subnets\Schemas\SubnetForm;
use App\Filament\Resources\Subnets\Tables\SubnetsTable;
use App\Models\Subnet;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;
use BackedEnum;

class SubnetResource extends Resource
{
    protected static ?string $model = Subnet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static UnitEnum|string|null $navigationGroup = 'IP Management';
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return SubnetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubnetsTable::configure($table);
    }


    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->with(['section', 'vlan', 'vrf', 'scanAgent', 'customer', 'location']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubnets::route('/'),
            'create' => CreateSubnet::route('/create'),
            'edit' => EditSubnet::route('/{record}/edit'),
        ];
    }
}

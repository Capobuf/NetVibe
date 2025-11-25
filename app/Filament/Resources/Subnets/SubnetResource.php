<?php

namespace App\Filament\Resources\Subnets;

use App\Filament\Resources\Subnets\Pages\CreateSubnet;
use App\Filament\Resources\Subnets\Pages\EditSubnet;
use App\Filament\Resources\Subnets\Pages\ListSubnets;
use App\Filament\Resources\Subnets\Schemas\SubnetForm;
use App\Filament\Resources\Subnets\Tables\SubnetsTable;
use App\Models\Subnet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SubnetResource extends Resource
{
    protected static ?string $model = Subnet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

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

<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets;

use App\Filament\Clusters\Ipam\IpamCluster;
use App\Filament\Resources\Subnets\RelationManagers\IpAddressesRelationManager;
use App\Models\Subnet;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use BackedEnum;

class SubnetResource extends Resource
{
    protected static ?string $model = Subnet::class;

    protected static string|BackedEnum|null $navigationIcon = null; // cluster will provide visual grouping

    protected static ?string $cluster = IpamCluster::class;

    public static function form(Schema $schema): Schema
    {
        return \App\Filament\Resources\Subnets\Schemas\SubnetForm::form($schema);
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Resources\Subnets\Tables\SubnetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [IpAddressesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\Subnets\Pages\ListSubnets::route('/'),
            'create' => \App\Filament\Resources\Subnets\Pages\CreateSubnet::route('/create'),
            'edit' => \App\Filament\Resources\Subnets\Pages\EditSubnet::route('/{record}/edit'),
        ];
    }
}

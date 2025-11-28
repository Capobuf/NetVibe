<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\IpAddresses;

use App\Filament\Clusters\Ipam\IpamCluster;
use App\Models\IpAddress;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class IpAddressResource extends Resource
{
    protected static ?string $model = IpAddress::class;

    // We do NOT want another top-level navigation entry for IPs inside the IPAM cluster
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $cluster = IpamCluster::class;

    public static function form(Schema $schema): Schema
    {
        return \App\Filament\Resources\IpAddresses\Schemas\IpAddressForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Resources\IpAddresses\Tables\IpAddressesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            // Relation managers are handled on the Subnet resource; this resource is intentionally minimal
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->with(['subnet', 'location', 'customer', 'switchDevice']);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\IpAddresses\Pages\ListIpAddresses::route('/'),
            'create' => \App\Filament\Resources\IpAddresses\Pages\CreateIpAddress::route('/create'),
            'edit' => \App\Filament\Resources\IpAddresses\Pages\EditIpAddress::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\IpAddresses;

use App\Filament\Resources\IpAddresses\Pages\CreateIpAddress;
use App\Filament\Resources\IpAddresses\Pages\EditIpAddress;
use App\Filament\Resources\IpAddresses\Pages\ListIpAddresses;
use App\Filament\Resources\IpAddresses\Schemas\IpAddressForm;
use App\Filament\Resources\IpAddresses\Tables\IpAddressesTable;
use App\Models\IpAddress;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;
use BackedEnum;

class IpAddressResource extends Resource
{
    protected static ?string $model = IpAddress::class;

    protected static ?string $modelLabel = 'IP Address';
    protected static ?string $pluralModelLabel = 'IP Addresses';
    protected static ?string $navigationLabel = 'IP Addresses';

    // Hide the top-level navigation for IP Addresses — they live inside the IPAM Cluster
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static UnitEnum|string|null $navigationGroup = 'IP Management';
    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return IpAddressForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IpAddressesTable::configure($table);
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
            ->with(['subnet', 'location', 'customer', 'switchDevice']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIpAddresses::route('/'),
            'create' => CreateIpAddress::route('/create'),
            'edit' => EditIpAddress::route('/{record}/edit'),
        ];
    }
}

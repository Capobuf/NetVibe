<?php

namespace App\Filament\Clusters\Racks\Resources\Devices;

use App\Filament\Clusters\Racks\RacksCluster;
use App\Filament\Clusters\Racks\Resources\Devices\Pages\CreateDevice;
use App\Filament\Clusters\Racks\Resources\Devices\Pages\EditDevice;
use App\Filament\Clusters\Racks\Resources\Devices\Pages\ListDevices;
use App\Filament\Clusters\Racks\Resources\Devices\Schemas\DeviceForm;
use App\Filament\Clusters\Racks\Resources\Devices\Tables\DevicesTable;
use App\Models\Device;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;
use BackedEnum;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;
    
    // Nest this resource under the Racks cluster
    protected static ?string $cluster = RacksCluster::class;
    
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return DeviceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DevicesTable::configure($table);
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
            'index' => ListDevices::route('/'),
            'create' => CreateDevice::route('/create'),
            'edit' => EditDevice::route('/{record}/edit'),
        ];
    }
}

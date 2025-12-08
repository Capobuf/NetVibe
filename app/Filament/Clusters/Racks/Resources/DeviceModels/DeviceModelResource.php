<?php

namespace App\Filament\Clusters\Racks\Resources\DeviceModels;

use App\Filament\Clusters\Racks\RacksCluster;
use App\Filament\Clusters\Racks\Resources\DeviceModels\Pages\CreateDeviceModel;
use App\Filament\Clusters\Racks\Resources\DeviceModels\Pages\EditDeviceModel;
use App\Filament\Clusters\Racks\Resources\DeviceModels\Pages\ListDeviceModels;
use App\Filament\Clusters\Racks\Resources\DeviceModels\Schemas\DeviceModelForm;
use App\Filament\Clusters\Racks\Resources\DeviceModels\Tables\DeviceModelsTable;
use App\Models\DeviceModel;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use BackedEnum;

class DeviceModelResource extends Resource
{
    protected static ?string $model = DeviceModel::class;

    // Navigation icon removed to avoid conflict with group icon in topbar
    protected static string|BackedEnum|null $navigationIcon = null;
    
    // Detached from cluster to allow grouped navigation under 'Racks & Devices'
    // protected static ?string $cluster = RacksCluster::class;

    // Ensure the resource appears under Racks & Devices dropdown
    protected static string | \UnitEnum | null $navigationGroup = 'Racks & Devices';
    
    protected static ?int $navigationSort = 20;
    
    protected static ?string $modelLabel = 'Device Model';
    protected static ?string $pluralModelLabel = 'Device Models';

    public static function form(Schema $schema): Schema
    {
        return DeviceModelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeviceModelsTable::configure($table);
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
            'index' => ListDeviceModels::route('/'),
            'create' => CreateDeviceModel::route('/create'),
            'edit' => EditDeviceModel::route('/{record}/edit'),
        ];
    }
}

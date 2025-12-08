<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure\Resources\Devices;

use App\Filament\Clusters\Infrastructure\InfrastructureCluster;
use App\Filament\Clusters\Infrastructure\Resources\Devices\Pages\CreateDevice;
use App\Filament\Clusters\Infrastructure\Resources\Devices\Pages\EditDevice;
use App\Filament\Clusters\Infrastructure\Resources\Devices\Pages\ListDevices;
use App\Filament\Clusters\Infrastructure\Resources\Devices\Pages\ViewDevice;
use App\Filament\Clusters\Infrastructure\Resources\Devices\Schemas\DeviceForm;
use App\Filament\Clusters\Infrastructure\Resources\Devices\Tables\DevicesTable;
use App\Models\Device;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use BackedEnum;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    // Hide this duplicate resource from navigation; the canonical device UI lives
    // under Racks & Devices. Keep the class available for route reuse if needed.
    protected static bool $shouldRegisterNavigation = false;
    protected static string | \UnitEnum | null $navigationGroup = null;
    // Change slug to avoid routing collisions with the canonical Device resource.
    protected static ?string $slug = 'infra-devices';

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
            \App\Filament\RelationManagers\AgentJobsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDevices::route('/'),
            'create' => CreateDevice::route('/create'),
            'edit' => EditDevice::route('/{record}/edit'),
            'view' => ViewDevice::route('/{record}'),
        ];
    }
}

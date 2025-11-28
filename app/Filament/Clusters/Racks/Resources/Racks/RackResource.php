<?php

namespace App\Filament\Clusters\Racks\Resources\Racks;

use App\Filament\Clusters\Racks\RacksCluster;
use App\Filament\Clusters\Racks\Resources\Racks\Pages\CreateRack;
use App\Filament\Clusters\Racks\Resources\Racks\Pages\EditRack;
use App\Filament\Clusters\Racks\Resources\Racks\Pages\ListRacks;
use App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack;
use App\Filament\Clusters\Racks\Resources\Racks\Schemas\RackForm;
use App\Filament\Clusters\Racks\Resources\Racks\Tables\RacksTable;
use App\Filament\Infolists\Components\RackVisual;
use App\Models\Rack;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use BackedEnum;

class RackResource extends Resource
{
    protected static ?string $model = Rack::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServer;
    
    protected static ?string $cluster = RacksCluster::class;
    
    protected static ?int $navigationSort = 5; // Before Devices

    public static function form(Schema $schema): Schema
    {
        return RackForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RacksTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                RackVisual::make('structure'),
            ]);
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
            'index' => ListRacks::route('/'),
            'create' => CreateRack::route('/create'),
            'view' => ViewRack::route('/{record}'),
            'edit' => EditRack::route('/{record}/edit'),
        ];
    }
}

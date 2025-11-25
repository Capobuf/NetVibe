<?php

namespace App\Filament\Resources\ScanAgents;

use App\Filament\Resources\ScanAgents\Pages\CreateScanAgent;
use App\Filament\Resources\ScanAgents\Pages\EditScanAgent;
use App\Filament\Resources\ScanAgents\Pages\ListScanAgents;
use App\Filament\Resources\ScanAgents\Schemas\ScanAgentForm;
use App\Filament\Resources\ScanAgents\Tables\ScanAgentsTable;
use App\Models\ScanAgent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ScanAgentResource extends Resource
{
    protected static ?string $model = ScanAgent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ScanAgentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScanAgentsTable::configure($table);
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
            'index' => ListScanAgents::route('/'),
            'create' => CreateScanAgent::route('/create'),
            'edit' => EditScanAgent::route('/{record}/edit'),
        ];
    }
}

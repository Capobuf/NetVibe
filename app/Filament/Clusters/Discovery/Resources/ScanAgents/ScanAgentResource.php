<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\ScanAgents;

use App\Filament\Clusters\Discovery\DiscoveryCluster;
use App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages\CreateScanAgent;
use App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages\EditScanAgent;
use App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages\ListScanAgents;
use App\Filament\Clusters\Discovery\Resources\ScanAgents\Schemas\ScanAgentForm;
use App\Filament\Clusters\Discovery\Resources\ScanAgents\Tables\ScanAgentsTable;
use App\Models\ScanAgent;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Support\Icons\Heroicon;
use BackedEnum;

class ScanAgentResource extends Resource
{
    protected static ?string $model = ScanAgent::class;

    // Navigation icon removed to avoid conflict with group icon in topbar
    protected static string|BackedEnum|null $navigationIcon = null;

    // Detached from cluster; will appear under the 'Discovery' navigation group
    // protected static ?string $cluster = DiscoveryCluster::class;

    // Ensure the resource appears under the Discovery dropdown
    protected static string | \UnitEnum | null $navigationGroup = 'Discovery';

    protected static ?int $navigationSort = 5;

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
            RelationManagers\AgentLogsRelationManager::class,
            \App\Filament\RelationManagers\AgentJobsRelationManager::class,
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

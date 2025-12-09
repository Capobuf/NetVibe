<?php

namespace App\Filament\Clusters\Racks\Resources\Devices;

use App\Filament\Clusters\Racks\RacksCluster;
use App\Filament\Clusters\Racks\Resources\Devices\Pages\CreateDevice;
use App\Filament\Clusters\Racks\Resources\Devices\Pages\EditDevice;
use App\Filament\Clusters\Racks\Resources\Devices\Pages\ListDevices;
use App\Filament\Clusters\Racks\Resources\Devices\Pages\ViewDevice;
use App\Filament\Clusters\Racks\Resources\Devices\Schemas\DeviceForm;
use App\Filament\Clusters\Racks\Resources\Devices\Tables\DevicesTable;
use App\Models\Device;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Infolists;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;
use BackedEnum;
use App\Filament\Clusters\Racks\Resources\Devices\RelationManagers\PortsRelationManager;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    // Navigation icon removed to avoid conflict with group icon in topbar
    protected static string|BackedEnum|null $navigationIcon = null;
    
    // Detached from cluster to allow grouped navigation under 'Racks & Devices'
    // protected static ?string $cluster = RacksCluster::class;

    // Ensure the resource appears under Racks & Devices dropdown
    protected static string | \UnitEnum | null $navigationGroup = 'Racks & Devices';
    
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return DeviceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DevicesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make('Overview')
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('hostname')
                        ->label('Hostname')
                        ->weight('bold'),
                    \Filament\Infolists\Components\TextEntry::make('management_ip')
                        ->label('Management IP'),
                    \Filament\Infolists\Components\TextEntry::make('deviceModel.model')
                        ->label('Model'),
                    \Filament\Infolists\Components\TextEntry::make('serial_number')
                        ->label('Serial'),
                    \Filament\Infolists\Components\TextEntry::make('auth_status')
                        ->label('Auth Status'),
                    \Filament\Infolists\Components\TextEntry::make('last_seen_overall')
                        ->label('Last Seen')
                        ->dateTime(),
                    \Filament\Infolists\Components\TextEntry::make('scanAgent.name')
                        ->label('Agent'),
                ])
                ->columns(3),
            \Filament\Schemas\Components\Section::make('Discovery')
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('last_discovery_job_type')
                        ->label('Last Job Type')
                        ->state(function (Device $record) {
                            $job = $record->agentJobs()->latest('created_at')->first();

                            return $job?->type ?? '—';
                        }),
                    \Filament\Infolists\Components\TextEntry::make('last_discovery_job_status')
                        ->label('Last Job Status')
                        ->state(function (Device $record) {
                            $job = $record->agentJobs()->latest('created_at')->first();

                            return $job?->status ?? '—';
                        }),
                    \Filament\Infolists\Components\TextEntry::make('last_discovery_job_finished_at')
                        ->label('Last Job Finished At')
                        ->state(function (Device $record) {
                            $job = $record->agentJobs()->latest('created_at')->first();

                            return $job?->finished_at;
                        })
                        ->dateTime(),
                    \Filament\Infolists\Components\TextEntry::make('last_discovery_job_result')
                        ->label('Last Job Result (JSON)')
                        ->state(function (Device $record) {
                            $job = $record->agentJobs()->latest('created_at')->first();

                            if (! $job) {
                                return null;
                            }

                            return json_encode($job->result ?? new \stdClass(), JSON_PRETTY_PRINT);
                        })
                        ->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            PortsRelationManager::class,
            \App\Filament\RelationManagers\AgentJobsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDevices::route('/'),
            'create' => CreateDevice::route('/create'),
            'view' => ViewDevice::route('/{record}'),
            'edit' => EditDevice::route('/{record}/edit'),
        ];
    }
}

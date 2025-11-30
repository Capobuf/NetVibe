<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets;

use App\Filament\Clusters\Ipam\IpamCluster;
use App\Filament\Clusters\Ipam\Resources\Subnets\RelationManagers\IpAddressesRelationManager;
use App\Models\Subnet;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Section as FormSection;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Infolists;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use App\Jobs\ScanSubnetJob;
use BackedEnum;

class SubnetResource extends Resource
{
    protected static ?string $model = Subnet::class;

    protected static string|BackedEnum|null $navigationIcon = null; // cluster will provide visual grouping

    protected static ?string $cluster = IpamCluster::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            FormSection::make()->schema([
                Select::make('vrf_id')->relationship('vrf', 'name')->required(),
                Select::make('vlan_id')->relationship('vlan', 'name')->preload()->searchable()->nullable(),
                TextInput::make('cidr')->required(),
                Textarea::make('description'),
            ]),

            FormSection::make('Discovery Configuration')->collapsible()
                ->schema([
                    Toggle::make('discover_subnet')->label('Enable Auto-Discovery')->default(true),

                    Select::make('scan_interval')
                        ->label('Scan interval')
                        ->options([
                            5 => 'Every 5 Minutes',
                            60 => 'Every Hour',
                            1440 => 'Daily',
                        ])
                        ->nullable()
                        ->hint('Leave empty to use the global default'),

                    Select::make('scan_agent_id')->relationship('scanAgent', 'name')->nullable(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('cidr')
                ->searchable()
                ->sortable()
                ->url(fn (Subnet $record): string => static::getUrl('view', ['record' => $record])),
            Tables\Columns\TextColumn::make('vlan.name')->label('VLAN')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('description')->limit(50),
        ])->headerActions([
            CreateAction::make()->slideOver(),
        ])->recordActions([
            Action::make('scan')
                ->label('Scan Network')
                ->icon(Heroicon::OutlinedSignal)
                ->color('primary')
                ->action(function (Subnet $record) {
                    ScanSubnetJob::dispatch($record);
                    Notification::make()->title('Scan started')->success()->send();
                }),

            ViewAction::make(),
            EditAction::make()->slideOver(),
        ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['vlan']);
    }

    public static function getRelations(): array
    {
        // The IP table + visual map are now provided by a single panel widget
        // shown on the Subnet view page. Keep relations array empty here.
        return [];
    }

    public static function infolist(Schema $schema): Schema
    {
        // Show key subnet metadata and utilization
        return $schema
            ->components([
                TextEntry::make('customer.name')->label('Customer'),
                TextEntry::make('vlan.name')->label('VLAN'),
                TextEntry::make('vrf.name')->label('VRF'),
                TextEntry::make('gateway')->label('Gateway')->getStateUsing(fn ($record) => 
                    // prefer explicit gateway if present (type='gateway'), otherwise first usable IP
                    optional($record->ipAddresses()->where('type', 'gateway')->first())->ip_str ?? app(\App\Services\IpConversionService::class)->fromDecimal(bcadd((string)$record->subnet, '1'))?->toString()
                ),
                TextEntry::make('utilization')->label('Utilization')->getStateUsing(function ($record) {
                    $service = app(\App\Services\IpConversionService::class);
                    $total = (int) $service->calculateUsableHosts((int) $record->mask);
                    $used = (int) $record->ipAddresses()->count();
                    return $total > 0 ? round(($used / $total) * 100, 1) . '%' : 'N/A';
                }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubnets::route('/'),
            'create' => Pages\CreateSubnet::route('/create'),
            'view' => Pages\ViewSubnet::route('/{record}'),
            'edit' => Pages\EditSubnet::route('/{record}/edit'),
        ];
    }
}

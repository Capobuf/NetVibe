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
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Forms\Components\Select;
use App\Models\Vrf;
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
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use App\Jobs\ScanSubnetJob;
use BackedEnum;

class SubnetResource extends Resource
{
    protected static ?string $model = Subnet::class;

    protected static string|BackedEnum|null $navigationIcon = null; // cluster will provide visual grouping

    protected static ?string $cluster = IpamCluster::class;

    // Ordering in IPAM navigation
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        // Ensure form uses only Forms components (no Schema layout components inside forms)
        return $schema->schema([
            Select::make('vrf_id')
                ->relationship('vrf', 'name')
                ->required()
                ->default(fn () => Vrf::first()?->id)
                ->hidden(fn () => Vrf::count() === 1)
                ->dehydratedWhenHidden()
                ->label('VRF'),

            Select::make('vlan_id')
                ->relationship('vlan', 'name')
                ->preload()
                ->searchable()
                ->nullable()
                ->label('VLAN'),

            TextInput::make('cidr')
                ->required()
                ->label('CIDR')
                ->rules([
                    'required',
                    new \App\Rules\ValidCidr(),
                ]),

            Textarea::make('description'),

            // Discovery configuration inputs — keep the individual inputs inline
            Toggle::make('discover_subnet')->label('Enable Auto-Discovery')->default(true)->hiddenOn('create'),

            Select::make('scan_interval')
                ->label('Scan interval')
                ->options([
                    5 => 'Every 5 Minutes',
                    60 => 'Every Hour',
                    1440 => 'Daily',
                ])
                ->nullable()
                ->hint('Leave empty to use the global default')
                ->hiddenOn('create'),

            Select::make('scan_agent_id')->relationship('scanAgent', 'name')->nullable()->hiddenOn('create'),
        ])->columns(1);
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
        // The IP table + visual map are provided on the Subnet view page. Only
        // expose the relation manager when rendering the resource *view* page so
        // it does not appear on edit pages (we keep edits metadata-only).
        if (app()->runningInConsole()) {
            return [];
        }

        $path = request()->path() ?? '';

        if (str_contains($path, '/view')) {
            return [
                IpAddressesRelationManager::class,
            ];
        }

        return [];
    }

    public static function infolist(Schema $schema): Schema
    {
        // Show key subnet metadata and utilization
        return $schema
            ->components([
                Section::make('Network')->schema([
                    TextEntry::make('cidr')->label('Network'),
                    TextEntry::make('gateway')->label('Gateway')->getStateUsing(fn ($record) => 
                    // prefer explicit gateway if present (type='gateway'), otherwise first usable IP
                    optional($record->ipAddresses()->where('type', 'gateway')->first())->ip_str ?? app(\App\Services\IpConversionService::class)->fromDecimal(bcadd((string)$record->subnet, '1'))?->toString()
                ),
                ]),
                Section::make('Metadata')->schema([
                    TextEntry::make('vlan.name')->label('VLAN'),
                    TextEntry::make('customer.name')->label('Customer'),
                    TextEntry::make('vrf.name')->label('VRF'),
                    TextEntry::make('utilization')->label('Utilization')->getStateUsing(function ($record) {
                        $service = app(\App\Services\IpConversionService::class);
                        $total = (int) $service->calculateUsableHosts((int) $record->mask);
                        $used = (int) $record->ipAddresses()->count();
                        return $total > 0 ? round(($used / $total) * 100, 1) . '%' : 'N/A';
                    }),
                ]),
                // (additional infolist entries could go here)
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubnets::route('/'),
            'view' => Pages\ViewSubnet::route('/{record}'),
        ];
    }
}

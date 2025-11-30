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
                ->label('VLAN')
                ->reactive()
                ->afterStateUpdated(function ($state, callable $set) {
                    if ($state) {
                        $vlan = \App\Models\Vlan::find($state);
                        if ($vlan && $vlan->customer_id) {
                            $set('customer_id', $vlan->customer_id);
                        }
                    }
                }),

            Select::make('customer_id')
                ->relationship('customer', 'name')
                ->searchable()
                ->nullable()
                ->label('Customer')
                ->helperText('Automatically inherited from VLAN if selected'),

            TextInput::make('cidr')
                ->required()
                ->label('CIDR')
                ->rules([
                    'required',
                    new \App\Rules\ValidCidr(),
                ]),

            Textarea::make('description'),

            // Discovery configuration inputs
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
            ViewAction::make(),
            EditAction::make()->slideOver(),
            // DeleteAction removed from table record actions per CRUD UX policy
        ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['vlan']);
    }

    public static function getRelations(): array
    {
        return [
            IpAddressesRelationManager::class,
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        // Show key subnet metadata and utilization
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Subnet Details')
                    ->columns(4)
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('network')
                            // Use the model accessor to return a formatted CIDR string (getCidrAttribute)
                            ->state(fn ($record) => $record->cidr)
                            ->label('Network')
                            ->weight('bold'),
                        \Filament\Infolists\Components\TextEntry::make('mask')->label('Netmask'),
                        \Filament\Infolists\Components\TextEntry::make('gateway')->label('Gateway')->getStateUsing(fn ($record) => 
                            optional($record->ipAddresses()->where('type', 'gateway')->first())->ip_str ?? app(\App\Services\IpConversionService::class)->fromDecimal(bcadd((string)$record->subnet, '1'))?->toString()
                        ),
                        \Filament\Infolists\Components\TextEntry::make('vlan.number')
                            ->label('VLAN')
                            ->badge(),
                        \Filament\Infolists\Components\TextEntry::make('vrf.name')->label('VRF'),
                        \Filament\Infolists\Components\TextEntry::make('customer.name')->label('Customer'),
                        \Filament\Infolists\Components\TextEntry::make('utilization')
                            ->state(function ($record) {
                                $service = app(\App\Services\IpConversionService::class);
                                $total = (int) $service->calculateUsableHosts((int) $record->mask);
                                $used = (int) $record->ipAddresses()->count();
                                return $used . ' / ' . $total;
                            })
                            ->label('Utilization')
                            ->badge()
                            ->color('gray'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubnets::route('/'),
            'view' => Pages\ViewSubnet::route('/{record}'),
            'edit' => Pages\EditSubnet::route('/{record}/edit'),
        ];
    }
}

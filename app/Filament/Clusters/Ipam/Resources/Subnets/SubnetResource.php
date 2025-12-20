<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets;

use App\Filament\Clusters\Ipam\IpamCluster;
use App\Filament\Clusters\Ipam\Resources\Subnets\RelationManagers;
use App\Models\Subnet;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Infolists;
use Filament\Infolists\Components\TextEntry;
use App\Filament\Clusters\Ipam\Resources\Vlans\VlanResource;
use App\Filament\Clusters\Organization\Resources\Customers\CustomerResource;
use App\Filament\Clusters\Organization\Resources\Locations\LocationResource;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use App\Jobs\ScanSubnetJob;
use BackedEnum;
use App\Filament\Exports\SubnetExporter;
use Torgodly\Html2Media\Tables\Actions\Html2MediaAction;

class SubnetResource extends Resource
{
    // Ensure the resource appears under the IPAM top-navigation dropdown
    // Filament (and PHP 8.4) allows enums in the signature; match parent type
    protected static string | \UnitEnum | null $navigationGroup = 'IPAM';
    protected static ?string $model = Subnet::class;

    protected static string|BackedEnum|null $navigationIcon = null; // cluster will provide visual grouping

    // Detached from cluster to allow top-nav dropdown driven by navigationGroup
    // protected static ?string $cluster = IpamCluster::class;

    // Ordering in IPAM navigation
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        // Ensure form uses only Forms components (no Schema layout components inside forms)
        return $schema->schema([
            // VRF removed: tenancy by customer. Customer selection remains nullable and can be inherited from VLAN.

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
                ->label('Customer')
                ->helperText('Automatically inherited from VLAN if selected')
                // Default to tenant if present and hide the control in tenant mode
                ->default(fn () => session('tenant_id'))
                ->hidden(fn () => ! blank(session('tenant_id')))
                ->dehydratedWhenHidden()
                // In God mode the field must be visible, required and reactive
                ->live()
                ->required(fn () => blank(session('tenant_id')))
                ->afterStateUpdated(function ($state, callable $set) {
                    // Changing customer should clear any dependent selections
                    $set('vlan_id', null);
                    $set('location_id', null);
                }),
            
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

            Select::make('scan_agent_id')
                ->label('Remote Agent')
                ->options(function ($get) {
                    $customerId = $get('customer_id') ?? null;
                    if (! $customerId) {
                        return [];
                    }
                    return \App\Models\ScanAgent::query()
                        ->where('active', true)
                        ->where('customer_id', $customerId)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray();
                })
                ->required(function ($get) {
                    $customerId = $get('customer_id') ?? null;
                    if (! $customerId) {
                        return false;
                    }
                    return \App\Models\ScanAgent::where('active', true)->where('customer_id', $customerId)->count() > 1;
                })
                ->hint(function ($get) {
                    $customerId = $get('customer_id') ?? null;
                    if (! $customerId) return null;
                    $count = \App\Models\ScanAgent::where('active', true)->where('customer_id', $customerId)->count();
                    return $count > 1 ? 'Più agent attivi per questo cliente: seleziona quello corretto.' : null;
                })
                ->hintColor('warning')
                ->nullable(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
                ->groups([
                \Filament\Tables\Grouping\Group::make('vlan.name')
                    ->label('VLAN')
                    ->collapsible()
                    ->getTitleFromRecordUsing(fn ($record) => ($record->vlan ? (string) ($record->vlan->number . ' - ' . $record->vlan->name) : 'No VLAN')),
            ])
                ->defaultGroup('vlan.name')
            ->headerActions([
                CreateAction::make()->slideOver(),
                ExportAction::make()
                    ->exporter(SubnetExporter::class),
                Html2MediaAction::make('export_pdf')
                    ->label('Generate PDF')
                    ->savePdf()
                    ->preview()
                    ->filename('subnets')
                    ->orientation('portrait')
                    ->format('a4', 'mm')
                    ->content(fn () => view('exports.subnets', [
                        'records' => \App\Models\Subnet::with(['vlan', 'customer', 'location'])->orderBy('cidr')->get(),
                    ])),
            ])
            ->columns([
            Tables\Columns\TextColumn::make('cidr')
                ->searchable()
                ->sortable()
                ->url(fn (Subnet $record): string => static::getUrl('view', ['record' => $record])),
            Tables\Columns\TextColumn::make('vlan.name')->label('VLAN')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('location.name')->label('Location')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('description')->limit(50),
                Tables\Columns\TextColumn::make('last_scan')
                    ->label('Last Scan')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_discovery')
                    ->label('Last Discovery')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('is_blacklisted')
                    ->label('Blacklisted')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Yes' : 'No')
                    ->color(fn (bool $state) => $state ? 'danger' : 'gray'),
                // Utilization summary: uses ip_addresses_count (withCount in query) and IpConversionService
                Tables\Columns\TextColumn::make('utilization_summary')
                    ->label('Utilization')
                    ->formatStateUsing(function ($state, $record) {
                        $service = app(\App\Services\IpConversionService::class);
                        $total = (int) $service->calculateUsableHosts((int) $record->mask);
                        $used = (int) ($record->ip_addresses_count ?? $record->ipAddresses()->count());
                        return $used . ' / ' . $total;
                    })
                    ->badge()
                    ->color(function ($state, $record) {
                        $service = app(\App\Services\IpConversionService::class);
                        $total = (int) $service->calculateUsableHosts((int) $record->mask);
                        $used = (int) ($record->ip_addresses_count ?? $record->ipAddresses()->count());
                        if ($total === 0) return 'secondary';
                        $pct = ($used / max(1, $total)) * 100;
                        if ($pct < 70) return 'success';
                        if ($pct < 90) return 'warning';
                        return 'danger';
                    })
        ])->filters([
            \Filament\Tables\Filters\SelectFilter::make('scan_agent_id')
                ->label('Remote Agent')
                ->relationship('scanAgent', 'name'),
        ])->recordActions([
            ViewAction::make(),
            EditAction::make()->slideOver(),
            \Filament\Actions\Action::make('blacklist_subnet')
                ->label(fn (Subnet $record) => $record->is_blacklisted ? 'Remove from blacklist' : 'Blacklist')
                ->color(fn (Subnet $record) => $record->is_blacklisted ? 'secondary' : 'danger')
                ->requiresConfirmation()
                ->action(function (Subnet $record): void {
                    if (! $record->cidr || ! $record->customer_id) {
                        return;
                    }

                    if ($record->is_blacklisted) {
                        $record->subnetBlacklists()->delete();
                    } else {
                        \App\Models\SubnetBlacklist::firstOrCreate([
                            'customer_id' => $record->customer_id,
                            'subnet_cidr' => $record->cidr,
                        ]);
                    }
                }),
            // DeleteAction removed da record actions per policy CRUD
        ])
        ->bulkActions([
            ExportBulkAction::make()
                ->exporter(SubnetExporter::class),
        ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->with(['vlan', 'customer', 'location', 'subnetBlacklists'])
            ->withCount('ipAddresses');
    }

    public static function getRelations(): array
    {
        return [
            // Group relation managers under a logical "Management" relation group
            RelationGroup::make('Management', [
                RelationManagers\IpAddressesRelationManager::class,
            ])->icon('heroicon-o-server'),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        // Show key subnet metadata and utilization
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Subnet Details')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('network')
                            // Use the model accessor to return a formatted CIDR string (getCidrAttribute)
                            ->state(fn ($record) => $record->cidr)
                            ->label('Network')
                            ->weight('bold')
                            ->copyable(),
                        \Filament\Infolists\Components\TextEntry::make('mask')->label('Netmask')->copyable(),
                        \Filament\Infolists\Components\TextEntry::make('gateway')->label('Gateway')
                            ->getStateUsing(fn ($record) => 
                                optional($record->ipAddresses()->where('type', 'gateway')->first())->ip_str ?? app(\App\Services\IpConversionService::class)->fromDecimal(bcadd((string)$record->subnet, '1'))?->toString()
                            )
                            ->copyable(),
                        \Filament\Infolists\Components\TextEntry::make('vlan.number')
                            ->label('VLAN')
                            ->badge()
                            ->url(function ($record) {
                                if (! $record || ! $record->vlan) return null;
                                $pages = VlanResource::getPages();
                                return array_key_exists('edit', $pages)
                                    ? VlanResource::getUrl('edit', ['record' => $record->vlan])
                                    : VlanResource::getUrl('index');
                            })
                            ->openUrlInNewTab(),
                        // VRF removed — no longer shown
                        \Filament\Infolists\Components\TextEntry::make('customer.name')
                            ->label('Customer')
                            ->url(function ($record) {
                                if (! $record || ! $record->customer) return null;
                                $pages = CustomerResource::getPages();
                                return array_key_exists('edit', $pages)
                                    ? CustomerResource::getUrl('edit', ['record' => $record->customer])
                                    : CustomerResource::getUrl('index');
                            })
                            ->openUrlInNewTab(),
                        \Filament\Infolists\Components\TextEntry::make('location.name')
                            ->label('Location')
                            ->url(function ($record) {
                                if (! $record || ! $record->location) return null;
                                $pages = LocationResource::getPages();
                                return array_key_exists('edit', $pages)
                                    ? LocationResource::getUrl('edit', ['record' => $record->location])
                                    : LocationResource::getUrl('index');
                            })
                            ->openUrlInNewTab(),
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
            'create' => Pages\CreateSubnet::route('/create'),
            'view' => Pages\ViewSubnet::route('/{record}'),
            'edit' => Pages\EditSubnet::route('/{record}/edit'),
        ];
    }
}

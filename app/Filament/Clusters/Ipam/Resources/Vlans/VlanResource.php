<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Vlans;

use App\Filament\Clusters\Ipam\IpamCluster;
use App\Models\Vlan;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Tables\Table;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use BackedEnum;

class VlanResource extends Resource
{
    // Ensure the resource appears under the IPAM top-navigation dropdown
    // Filament (and PHP 8.4) allows enums in the signature; match parent type
    protected static string | \UnitEnum | null $navigationGroup = 'IPAM';
    protected static ?string $model = Vlan::class;

    // Detached from cluster to allow top-nav dropdown driven by navigationGroup
    // protected static ?string $cluster = IpamCluster::class;

    // Ordering in IPAM navigation
    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = null;

    // Normalize labels and capitalization
    protected static ?string $navigationLabel = 'VLANs';
    protected static ?string $modelLabel = 'VLAN';
    protected static ?string $pluralModelLabel = 'VLANs';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('General')->schema([
                TextInput::make('number')->numeric()->required()->label('VLAN ID'),
                TextInput::make('name')->required()->maxLength(255),
                // L2Domain removed — VLANs are tenant-scoped by customer
                Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->preload()
                    ->searchable()
                    // Default to session tenant when present and hide the control in tenant mode
                    // If a tenant is selected, default to it and hide the field
                    ->default(fn () => session('tenant_id'))
                    ->hidden(fn () => ! blank(session('tenant_id')))
                    ->dehydratedWhenHidden()
                    ->live()
                    ->required(fn () => blank(session('tenant_id')))
                    ->afterStateUpdated(fn ($state, callable $set) => $set('location_id', null)),
                TextInput::make('description')->nullable(),
            ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('customer.name')->label('Customer')->sortable(),
                TextColumn::make('subnets_count')->label('Subnets')->sortable(),
                TextColumn::make('is_blacklisted')
                    ->label('Blacklisted')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Yes' : 'No')
                    ->color(fn (bool $state) => $state ? 'danger' : 'gray'),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()->slideOver(),
                \Filament\Actions\Action::make('blacklist')
                    ->label(fn (Vlan $record) => $record->is_blacklisted ? 'Remove from blacklist' : 'Blacklist')
                    ->color(fn (Vlan $record) => $record->is_blacklisted ? 'secondary' : 'danger')
                    ->requiresConfirmation()
                    ->action(function (Vlan $record): void {
                        if ($record->is_blacklisted) {
                            $record->blacklist()->delete();
                        } else {
                            \App\Models\VlanBlacklist::firstOrCreate([
                                'customer_id' => $record->customer_id,
                                'vlan_id' => $record->id,
                            ]);
                        }
                    }),
                // DeleteAction removed from table record actions per CRUD UX policy
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'blacklist'])
            ->withCount('subnets');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVlans::route('/'),
            'edit' => Pages\EditVlan::route('/{record}/edit'),
        ];
    }
}

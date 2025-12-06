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
    protected static ?string $model = Vlan::class;

    protected static ?string $cluster = IpamCluster::class;

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
                Select::make('customer_id')->relationship('customer', 'name')->preload()->searchable()->nullable(),
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
                // L2Domain removed
                TextColumn::make('customer.name')->label('Customer')->sortable(),
                TextColumn::make('subnets_count')->label('Subnets')->sortable(),
            ])
            ->recordActions([
                    \Filament\Actions\EditAction::make()->slideOver(),
                    // DeleteAction removed from table record actions per CRUD UX policy
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['customer'])->withCount('subnets');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVlans::route('/'),
            'edit' => Pages\EditVlan::route('/{record}/edit'),
        ];
    }
}

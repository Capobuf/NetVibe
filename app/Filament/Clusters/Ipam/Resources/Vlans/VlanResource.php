<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Vlans;

use App\Filament\Clusters\Ipam\IpamCluster;
use App\Models\Vlan;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use BackedEnum;

class VlanResource extends Resource
{
    protected static ?string $model = Vlan::class;

    protected static ?string $cluster = IpamCluster::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    // Normalize labels and capitalization
    protected static ?string $navigationLabel = 'VLANs';
    protected static ?string $modelLabel = 'VLAN';
    protected static ?string $pluralModelLabel = 'VLANs';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                TextInput::make('number')->numeric()->required(),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('l2_domain_id')->relationship('l2Domain', 'name')->preload()->searchable()->nullable(),
                Select::make('customer_id')->relationship('customer', 'name')->preload()->searchable()->nullable(),
                TextInput::make('description')->nullable(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('l2Domain.name')->label('L2 Domain')->sortable(),
                TextColumn::make('customer.name')->label('Customer')->sortable(),
                TextColumn::make('subnets_count')->label('Subnets')->sortable(),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['l2Domain', 'customer'])->withCount('subnets');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVlans::route('/'),
            'create' => Pages\CreateVlan::route('/create'),
            'edit' => Pages\EditVlan::route('/{record}/edit'),
        ];
    }
}

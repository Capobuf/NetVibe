<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\L2Domains;

use App\Filament\Clusters\Ipam\IpamCluster;
use App\Models\L2Domain;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use BackedEnum;

class L2DomainResource extends Resource
{
    protected static ?string $model = L2Domain::class;

    protected static ?string $cluster = IpamCluster::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    // Normalize labels and capitalization
    protected static ?string $navigationLabel = 'L2 Domains';
    protected static ?string $modelLabel = 'L2 Domain';
    protected static ?string $pluralModelLabel = 'L2 Domains';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                TextInput::make('name')->required()->maxLength(255),
                Textarea::make('description'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('vlans_count')->label('VLANs')->sortable(),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->withCount('vlans');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListL2Domains::route('/'),
            'create' => Pages\CreateL2Domain::route('/create'),
            'edit' => Pages\EditL2Domain::route('/{record}/edit'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Organization\Resources\Locations;

use App\Models\Location;
use App\Filament\Clusters\Organization\Organization;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use BackedEnum;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static ?string $cluster = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Locations';

    public static function form(Schema $schema): Schema
    {
        // Use only form input components in the form schema (avoid Schema layout components here)
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('customer_id')
                ->relationship('customer', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->label('Customer')
                ->default(fn () => session('tenant_id'))
                ->hidden(fn () => ! blank(session('tenant_id')))
                ->dehydratedWhenHidden(),
            Textarea::make('address')->rows(3)->nullable(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('customer.name')->label('Customer')->sortable(),
            ])
            
            ->actions([
                EditAction::make()->slideOver(),
                // DeleteAction removed from table actions per CRUD UX policy
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/create'),
        ];
    }
}

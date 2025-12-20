<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Organization\Resources\Customers;

use App\Models\Customer;
use App\Filament\Clusters\Organization\Organization;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use BackedEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    // Detached from cluster to allow grouped navigation under 'Organization'
    // protected static ?string $cluster = Organization::class;

    // Ensure the resource appears under Organization dropdown
    protected static string | \UnitEnum | null $navigationGroup = 'Organization';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Customers';

    public static function form(Schema $schema): Schema
    {
        // Inline input components in the form schema; avoid using Schema layout components here
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('contact_email')->email()->nullable(),
            Textarea::make('address')->rows(3)->nullable(),
            Textarea::make('notes')->rows(5)->nullable(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('contact_email')->label('Email'),
                TextColumn::make('address')->limit(30)->tooltip(fn ($record) => $record->address),
            ])
            
            ->actions([
                EditAction::make()->slideOver(),
                // DeleteAction removed from table actions per CRUD UX policy
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
        ];
    }
}

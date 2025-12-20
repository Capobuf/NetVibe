<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Blacklists;

use App\Models\VlanBlacklist;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;

class VlanBlacklistResource extends Resource
{
    protected static ?string $model = VlanBlacklist::class;

    protected static string | \UnitEnum | null $navigationGroup = 'IPAM';
    protected static ?int $navigationSort = 90;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'VLAN Blacklist';
    protected static ?string $modelLabel = 'VLAN Blacklist Entry';
    protected static ?string $pluralModelLabel = 'VLAN Blacklist';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Blacklist Entry')->schema([
                Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->preload()
                    ->searchable()
                    ->default(fn () => session('tenant_id'))
                    ->hidden(fn () => ! blank(session('tenant_id')))
                    ->dehydratedWhenHidden()
                    ->required(fn () => blank(session('tenant_id'))),

                TextInput::make('vlan_number')
                    ->label('VLAN ID')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(4094)
                    ->required(),

                TextInput::make('vlan_name')
                    ->label('Name (optional)')
                    ->maxLength(255)
                    ->nullable(),

                TextInput::make('reason')
                    ->label('Reason (optional)')
                    ->maxLength(255)
                    ->nullable(),
            ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')->label('Customer')->sortable()->searchable(),
                TextColumn::make('vlan_number')->label('VLAN ID')->sortable()->searchable(),
                TextColumn::make('vlan_name')->label('Name')->toggleable(isToggledHiddenByDefault: true)->searchable(),
                TextColumn::make('reason')->toggleable(isToggledHiddenByDefault: true)->searchable(),
                TextColumn::make('created_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()->slideOver(),
                Action::make('remove')
                    ->label('Remove')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (VlanBlacklist $record) => $record->delete()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVlanBlacklists::route('/'),
            'edit' => Pages\EditVlanBlacklist::route('/{record}/edit'),
        ];
    }
}

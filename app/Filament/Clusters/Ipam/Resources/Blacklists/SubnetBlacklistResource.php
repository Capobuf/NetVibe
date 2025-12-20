<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Blacklists;

use App\Models\SubnetBlacklist;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;

class SubnetBlacklistResource extends Resource
{
    protected static ?string $model = SubnetBlacklist::class;

    protected static string | \UnitEnum | null $navigationGroup = 'IPAM';
    protected static ?int $navigationSort = 91;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Subnet Blacklist';
    protected static ?string $modelLabel = 'Subnet Blacklist Entry';
    protected static ?string $pluralModelLabel = 'Subnet Blacklist';

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

                TextInput::make('subnet_cidr')
                    ->label('Subnet (CIDR)')
                    ->maxLength(64)
                    ->required()
                    ->helperText('Example: 192.168.10.0/24'),

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
                TextColumn::make('subnet_cidr')->label('Subnet')->sortable()->searchable(),
                TextColumn::make('reason')->toggleable(isToggledHiddenByDefault: true)->searchable(),
                TextColumn::make('created_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()->slideOver(),
                Action::make('remove')
                    ->label('Remove')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (SubnetBlacklist $record) => $record->delete()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubnetBlacklists::route('/'),
            'edit' => Pages\EditSubnetBlacklist::route('/{record}/edit'),
        ];
    }
}

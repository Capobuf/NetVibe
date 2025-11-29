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
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use App\Jobs\ScanSubnetJob;
use BackedEnum;

class SubnetResource extends Resource
{
    protected static ?string $model = Subnet::class;

    protected static string|BackedEnum|null $navigationIcon = null; // cluster will provide visual grouping

    protected static ?string $cluster = IpamCluster::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                Select::make('vrf_id')->relationship('vrf', 'name')->required(),
                Select::make('vlan_id')->relationship('vlan', 'name')->preload()->searchable()->nullable(),
                TextInput::make('cidr')->required(),
                Textarea::make('description'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('cidr')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('vlan.name')->label('VLAN')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('description')->limit(50),
        ])->headerActions([
            CreateAction::make()->slideOver(),
        ])->recordActions([
            Action::make('scan')
                ->label('Scan Network')
                ->icon(Heroicon::OutlinedSignal)
                ->color('primary')
                ->action(function (Subnet $record) {
                    ScanSubnetJob::dispatch($record);
                    Notification::make()->title('Scan started')->success()->send();
                }),

            EditAction::make()->slideOver(),
        ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['vlan']);
    }

    public static function getRelations(): array
    {
        return [IpAddressesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubnets::route('/'),
            'create' => Pages\CreateSubnet::route('/create'),
            'edit' => Pages\EditSubnet::route('/{record}/edit'),
        ];
    }
}

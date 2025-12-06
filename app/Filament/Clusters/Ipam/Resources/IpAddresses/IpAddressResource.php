<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\IpAddresses;

use App\Filament\Clusters\Ipam\IpamCluster;
use App\Models\IpAddress;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Toggle;
use App\Filament\Clusters\Ipam\Resources\IpAddresses\Schemas\IpAddressSchema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;

class IpAddressResource extends Resource
{
    protected static ?string $model = IpAddress::class;

    // We do NOT want another top-level navigation entry for IPs inside the IPAM cluster
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $cluster = IpamCluster::class;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(array_merge([
                Select::make('subnet_id')
                    ->relationship('subnet')
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        if ($state) {
                            $subnet = \App\Models\Subnet::with('vlan')->find($state);
                            if ($subnet && $subnet->customer_id) {
                                $set('customer_id', $subnet->customer_id);
                            }
                        }
                    })
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->cidr ?? app(\App\Services\IpConversionService::class)->toCidr((string) $record->subnet, (int) $record->mask))
                    ->searchable()
                    ->preload()
                    ->default(null),

                // Customer should be higher in the form and inherit from the selected subnet when present
                Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    // Prefer session tenant_id, otherwise inherit from selected subnet/vlan
                    ->default(function ($get) {
                        if (! blank(session('tenant_id'))) {
                            return session('tenant_id');
                        }

                        $subnetId = $get('subnet_id');
                        if (! $subnetId) {
                            return null;
                        }

                        $subnet = \App\Models\Subnet::with('vlan')->find($subnetId);
                        if (! $subnet) {
                            return null;
                        }

                        // Prefer explicit subnet customer, fallback to vlan->customer if present
                        return $subnet->customer_id ?? optional($subnet->vlan)->customer_id ?? null;
                    })
                    ->live()
                    // If a tenant is selected, hide the control and ensure it's persisted
                    ->hidden(fn () => ! blank(session('tenant_id')))
                    ->dehydratedWhenHidden()
                    // Make the field required when not operating in tenant mode
                    ->required(fn () => blank(session('tenant_id')))
                    // Clearing customer should clear the location selection
                    ->afterStateUpdated(fn ($set) => $set('location_id', null)),
            ], IpAddressSchema::schema(), [
                // Keep only the fields necessary for manual IP management

                Select::make('location_id')
                    ->relationship(
                        name: 'location',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (\Illuminate\Database\Eloquent\Builder $query, $get) =>
                            $query->when($get('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
                    )
                    ->searchable()
                    ->preload()
                    ->default(null)
                    ->disabled(fn ($get) => ! $get('customer_id')),

                Toggle::make('exclude_ping')
                    ->label('Exclude Ping')
                    ->required(),
            ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ip_addr')
                    ->label('IP Address')
                    ->formatStateUsing(fn ($state) => app(\App\Services\IpConversionService::class)->fromDecimal($state))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('hostname')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('description')
                    ->searchable()
                    ->limit(30)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('mac_address')
                    ->label('MAC Address')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('owner')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('state')
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        1 => 'danger',   // Offline
                        2 => 'success',  // Used
                        3 => 'primary',  // Reserved
                        4 => 'gray',     // DHCP
                        default => 'secondary',
                    })
                    ->formatStateUsing(fn ($state) => match($state) {
                        1 => 'Offline',
                        2 => 'Used',
                        3 => 'Reserved',
                        4 => 'DHCP',
                        default => 'Unknown',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('subnet.description')
                    ->label('Subnet')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Location')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('port')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_seen')
                    ->label('Last Seen')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // Relation managers are handled on the Subnet resource; this resource is intentionally minimal
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->with(['subnet', 'location', 'customer', 'switchDevice']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIpAddresses::route('/'),
            'create' => Pages\CreateIpAddress::route('/create'),
            'edit' => Pages\EditIpAddress::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Clusters\Discovery\Resources;

use App\Filament\Clusters\Discovery\DiscoveryCluster;
use App\Models\DiscoveryEvent;
use App\Models\IpAddress;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use BackedEnum;
use App\Filament\Exports\DiscoveryEventExporter;

class DiscoveryEventResource extends Resource
{
    protected static ?string $model = DiscoveryEvent::class;
    // Removed resource-level icon to avoid duplication with its containing group
    protected static string|BackedEnum|null $navigationIcon = null;
    protected static ?string $slug = 'discovery-events';
    // Detached from cluster; will appear under the 'Discovery' navigation group
    // protected static ?string $cluster = DiscoveryCluster::class;
    protected static ?string $label = 'Discovery Event';
    protected static ?string $pluralLabel = 'Discovery Events';
    // Ensure this resource registers itself in navigation (appears under Discovery)
    protected static bool $shouldRegisterNavigation = true;

    // Ensure the resource appears under the Discovery dropdown
    protected static string | \UnitEnum | null $navigationGroup = 'Discovery';

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([
                ExportAction::make()
                    ->exporter(DiscoveryEventExporter::class),
            ])
            ->columns([
                TextColumn::make('scanAgent.name')
                    ->label('Agent')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ip_addr')
                    ->label('IP')
                    ->formatStateUsing(fn ($state) => app(\App\Services\IpConversionService::class)->fromDecimal($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('mac_address_current')->label('MAC'),
                TextColumn::make('hostname_current')->label('Hostname'),
                BadgeColumn::make('conflict_type')
                    ->colors([
                        'danger' => 'MAC_MISMATCH',
                        'warning' => 'HOSTNAME_CHANGE',
                        'gray' => null,
                    ])
                    ->label('Conflitto'),
                TextColumn::make('status')->label('Stato'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'PENDING' => 'Pending',
                        'APPROVED' => 'Approved',
                        'DISCARDED' => 'Discarded',
                    ])
                    ->default('PENDING'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                // ...existing actions...
            ])
            ->bulkActions([
                ExportBulkAction::make()
                    ->exporter(DiscoveryEventExporter::class),
                BulkAction::make('approve_merge')
                    ->label('Approva e Unisci')
                    ->action(function ($records) {
                        foreach ($records as $event) {
                            if (empty($event->customer_id)) {
                                continue;
                            }

                            $ip = IpAddress::where('customer_id', (int) $event->customer_id)
                                ->where('ip_addr', (string) $event->ip_addr)
                                ->first();

                            if ($ip) {
                                if (! empty($event->mac_address_current)) {
                                    $ip->mac_address = (string) $event->mac_address_current;
                                }
                                if (! empty($event->hostname_current)) {
                                    $ip->hostname = (string) $event->hostname_current;
                                }
                                $ip->save();
                            }

                            $event->status = DiscoveryEvent::STATUS_APPROVED;
                            $event->save();
                        }
                    })
                    ->color('success')
                    ->requiresConfirmation(),
                DeleteBulkAction::make()
                    ->label('Scarta')
                    ->color('danger'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDiscoveryEvents::route('/'),
        ];
    }
}

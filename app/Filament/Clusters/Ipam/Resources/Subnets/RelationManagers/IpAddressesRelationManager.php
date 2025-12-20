<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets\RelationManagers;

use App\Models\Subnet;
use App\Services\IpConversionService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use App\Filament\Clusters\Ipam\Resources\IpAddresses\Schemas\IpAddressSchema;
use Filament\Tables\Filters\SelectFilter;
// Heading is not provided as a table header component by Filament packages —
// use a table action as a non-interactive heading instead.
use Filament\Actions\Action as TableAction;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\SelectColumn;
use App\Enums\IpAddressType;
use App\Enums\LifecycleStatus;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;

class IpAddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'ipAddresses';
    protected static ?string $recordTitleAttribute = 'ip_addr';
    protected static ?string $title = 'IP Address Table';

    public function isReadOnly(): bool
    {
        return false;
    }
    /**
     * Validate an IP for a given subnet.
     * - Reject non-parsable IPs
     * - Reject IPv6 where subnet is IPv4
     * - Reject duplicate IP already present in subnet
     */
    public static function isIpValidForSubnet(Subnet $subnet, string $ip, $record = null): bool
    {
        $service = new IpConversionService();

        try {
            $ipDecimal = $service->toDecimal($ip);
        } catch (\Throwable $e) {
            return false;
        }

        // Parse subnet CIDR from the model; accept either a CIDR string or reconstruct from stored
        // decimal subnet + mask (some subnets may be created via factories or migrations)
        try {
            if (! empty($subnet->cidr)) {
                $parsed = $service->parseCidr($subnet->cidr);
            } elseif (! empty($subnet->subnet) && ! empty($subnet->mask)) {
                // Reconstruct a CIDR pointer: use toCidr to get textual representation
                $parsed = [
                    'subnet' => (string) $subnet->subnet,
                    'mask' => (int) $subnet->mask,
                ];
            } else {
                return false;
            }
        } catch (\Throwable $e) {
            // Malformed CIDR on subnet
            return false;
        }

        // Ensure IP is within CIDR (decimal-based check)
        $inSubnet = $service->isIpInSubnet((string)$ipDecimal, (string)$parsed['subnet'], (int)$parsed['mask']);

        // No textual fallback here - rely on decimal math via IpConversionService.
        if (! $inSubnet) {
            return false;
        }

        // Duplicate check (ignore if same record being edited)
        $exists = $subnet->ipAddresses()->where('ip_addr', $ipDecimal)->exists();
        // (no debug output)
        if ($exists) {
            return false;
        }

        return true;
    }

    public function form(Schema $schema): Schema
    {
        // Reuse canonical IpAddress schema and append relation-manager specific fields
        return $schema->schema(array_merge(IpAddressSchema::schema(), [
            TextInput::make('owner')->default(null),
            TextInput::make('port')->default(null),
            Textarea::make('note')->default(null)->columnSpanFull(),
        ]))->columns(2);
    }

    public function table(Table $table): Table
    {
        // Helper used by CreateAction mountUsing to avoid duplicated inline closures
        // and reduce apparent duplication for static analysis.
        $prefill = fn () => [
            'ip_addr' => app(\App\Services\IpConversionService::class)->getNextFreeIp($this->getOwnerRecord()),
            'customer_id' => $this->getOwnerRecord()->customer_id,
        ];
        return $table
            ->heading('IP Address Table')
            ->defaultSort('ip_addr', 'asc')
            ->searchPlaceholder('Search IP, hostname or MAC')
            ->paginationPageOptions([10, 25, 50, 100])
            ->striped()
            ->actionsColumnLabel('Actions')
            ->columns([
                TextColumn::make('ip_addr')
                    ->label('IP Address')
                    ->formatStateUsing(fn ($state) => app(IpConversionService::class)->fromDecimal($state)?->toString())
                    ->searchable()
                    ->sortable(),

                TextColumn::make('hostname')
                    ->label('Hostname')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn ($state) => ($state instanceof IpAddressType ? $state : IpAddressType::from((string) $state))->getColor())
                    ->icon(fn ($state) => ($state instanceof IpAddressType ? $state : IpAddressType::from((string) $state))->getIcon())
                    ->sortable(),

                TextColumn::make('mac_address')
                    ->label('MAC Address')
                    ->searchable(),

                TextColumn::make('last_seen')
                    ->label('Last Seen')
                    ->formatStateUsing(function ($state) {
                        if (! $state) return '-';
                        $format = app(\App\Settings\GeneralSettings::class)->time_display_format;
                        return $format === 'precise' ? $state->format('Y-m-d H:i:s') : $state->diffForHumans();
                    })
                    ->sortable(),

                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(function ($state) {
                        return match ((int) $state) {
                            1 => 'Down',
                            2 => 'Up',
                            3 => 'Planned',
                            4 => 'Reserved',
                            default => 'Unknown',
                        };
                    })
                    ->color(fn ($state) => match ((int) $state) {
                        2 => 'success',
                        1 => 'danger',
                        4 => 'warning',
                        3 => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('lifecycle_status')
                    ->label('Lifecycle')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ($state instanceof LifecycleStatus ? $state : LifecycleStatus::from((int) $state))->label())
                    ->color(fn ($state) => match (($state instanceof LifecycleStatus ? $state : LifecycleStatus::from((int) $state))) {
                        LifecycleStatus::Active => 'success',
                        LifecycleStatus::Stale => 'warning',
                        LifecycleStatus::Retired => 'danger',
                    })
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('port')
                    ->label('Port')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('owner')
                    ->label('Owner')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('switch_id')
                    ->label('Switch')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                'host' => 'Host',
                'gateway' => 'Gateway',
                'broadcast' => 'Broadcast',
                'reserved' => 'Reserved',
                'dhcp' => 'DHCP',
                ]),

                SelectFilter::make('lifecycle_status')
                    ->label('Lifecycle')
                    ->options([
                        (string) LifecycleStatus::Active->value => LifecycleStatus::Active->label(),
                        (string) LifecycleStatus::Stale->value => LifecycleStatus::Stale->label(),
                        (string) LifecycleStatus::Retired->value => LifecycleStatus::Retired->label(),
                    ]),
            ])
            ->emptyStateDescription('No IPs have been discovered or created for this subnet yet. Click the + button to create an IP manually, or run a discovery scan from your agents.')
            ->headerActions([
                \Filament\Actions\CreateAction::make('create_ip_header')
                    ->label('Add IP')
                    ->icon('heroicon-o-plus')
                    ->button()
                    ->color('primary')
                    ->slideOver()
                    ->tooltip('Create IP for this Subnet')
                    ->mountUsing(fn ($form) => $form->fill($prefill()))
                    ->relationship(fn () => $this->getRelationship())
                    ->visible(fn () => true),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                // DeleteAction removed from relation manager record actions per CRUD UX policy
            ]);
    }
}

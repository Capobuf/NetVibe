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
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;

class IpAddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'ipAddresses';
    protected static ?string $recordTitleAttribute = 'ip_addr';
    // Hide the auto-generated relation manager title to avoid duplicate headers
    protected static ?string $title = null;

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
        ]));
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
            ->defaultSort('ip_addr', 'asc')
            ->columns([
                TextColumn::make('ip_addr')
                    ->label('IP Address')
                    ->formatStateUsing(fn ($state) => app(IpConversionService::class)->fromDecimal($state)?->toString())
                    ->searchable()
                    ->sortable(),

                TextInputColumn::make('hostname')
                    ->label('Hostname')
                    ->rules(['nullable', 'max:255'])
                    ->inline()
                    ->searchable(),

                TextInputColumn::make('description')
                    ->label('Description')
                    ->rules(['nullable', 'max:255'])
                    ->inline()
                    ->searchable(),

                SelectColumn::make('type')
                    ->label('Type')
                    ->options(fn () => collect(IpAddressType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])->toArray())
                    ->inline()
                    ->sortable(),

                TextInputColumn::make('mac_address')
                    ->label('MAC Address')
                    ->rules(['nullable', 'regex:/^([0-9A-Fa-f]{2}(:|-)){5}[0-9A-Fa-f]{2}$/'])
                    ->inline()
                    ->searchable(),

                TextColumn::make('last_seen')
                    ->label('Last Seen')
                    ->formatStateUsing(function ($state) {
                        if (! $state) return '-';
                        $format = app(\App\Settings\GeneralSettings::class)->time_display_format;
                        return $format === 'precise' ? $state->format('Y-m-d H:i:s') : $state->diffForHumans();
                    })
                    ->sortable(),

                TextInputColumn::make('owner')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->rules(['nullable', 'max:255'])
                    ->inline(),

                TextInputColumn::make('switch_id')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->rules(['nullable', 'max:255'])
                    ->inline(),

                TextInputColumn::make('port')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->rules(['nullable', 'max:255'])
                    ->inline(),

                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        1 => 'danger',
                        2 => 'success',
                        3 => 'primary',
                        4 => 'gray',
                        default => 'secondary',
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                'host' => 'Host',
                'gateway' => 'Gateway',
                'broadcast' => 'Broadcast',
                'reserved' => 'Reserved',
                'dhcp' => 'DHCP',
                ]),
            ])
            ->emptyStateDescription('No IPs have been discovered or created for this subnet yet. Click the + button to create an IP manually, or run a discovery scan from your agents.')
            ->headerActions([
                \Filament\Actions\CreateAction::make('create_ip_header')
                    ->label('')
                    ->icon('heroicon-o-plus')
                    ->button()
                    ->color('primary')
                    ->slideOver()
                    ->tooltip('Create IP for this Subnet')
                    ->mountUsing(fn ($form) => $form->fill($prefill()))
                    ->relationship(fn () => $this->getRelationship())
                    ->visible(fn () => true),
            ])
            ->toolbarActions([])
            ->recordActions([
            EditAction::make()->slideOver(),
            // DeleteAction removed from relation manager record actions per CRUD UX policy
        ]);
    }
}

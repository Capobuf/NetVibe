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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;

class IpAddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'ipAddresses';
    protected static ?string $recordTitleAttribute = 'ip_addr';
    // Hide the auto-generated relation manager title to avoid duplicate headers
    protected static ?string $title = null;
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
        return $schema->schema([
            TextInput::make('ip_addr')->required(),
            TextInput::make('hostname')->default(null),
            TextInput::make('owner')->default(null),
            TextInput::make('mac_address')->default(null),
            TextInput::make('port')->default(null),
            Textarea::make('note')->default(null)->columnSpanFull(),
            Textarea::make('description')->default(null),
            Select::make('type')
                ->options([
                    'host' => 'Host',
                    'gateway' => 'Gateway',
                    'broadcast' => 'Broadcast',
                    'reserved' => 'Reserved',
                    'dhcp' => 'DHCP',
                ])
                ->default('host'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('ip_addr')
                ->label('IP Address')
                ->formatStateUsing(fn ($state) => app(IpConversionService::class)->fromDecimal($state))
                ->searchable()
                ->sortable(),
            Tables\Columns\TextInputColumn::make('hostname')
                ->label('Hostname')
                ->rules(['nullable', 'max:255'])
                ->inline()
                ->searchable(),

            TextInputColumn::make('owner')
                ->rules(['nullable', 'max:255'])
                ->inline()
                ->searchable(),

            TextInputColumn::make('port')
                ->rules(['nullable', 'max:255'])
                ->inline()
                ->searchable(),

            TextInputColumn::make('mac_address')
                ->label('MAC')
                ->rules(['nullable', 'max:255'])
                ->inline()
                ->fontFamily('mono')
                ->copyable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('description')->limit(30),
            Tables\Columns\TextColumn::make('state')
                ->label('Status')
                ->badge()
                ->color(fn ($state) => match($state) {
                    1 => 'danger',
                    2 => 'success',
                    3 => 'primary',
                    4 => 'gray',
                    default => 'secondary',
                })
                ->sortable(),
        ])->filters([
            SelectFilter::make('type')->options([
                'host' => 'Host',
                'gateway' => 'Gateway',
                'broadcast' => 'Broadcast',
                'reserved' => 'Reserved',
                'dhcp' => 'DHCP',
            ]),
        ])->headerActions([
            CreateAction::make()
                ->label('New')
                ->icon('heroicon-o-plus')
                ->button()
                ->slideOver(),
        ])->recordActions([
            EditAction::make()->slideOver(),
            DeleteAction::make(),
        ]);
    }
}

<?php

namespace App\Filament\Clusters\Racks\Resources\Devices\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Fieldset;

class DeviceForm
{
    private static function agentOptions(?int $customerId): array
    {
        $query = \App\Models\ScanAgent::query()->where('active', true);
        if ($customerId) {
            $query->where(function ($q) use ($customerId) {
                $q->where('customer_id', $customerId)->orWhereNull('customer_id');
            });
        }

        return $query->orderBy('customer_id')->orderBy('name')->pluck('name', 'id')->toArray();
    }

    private static function hasMultipleAgents(?int $customerId): bool
    {
        if (! $customerId) {
            return false;
        }

        return \App\Models\ScanAgent::where('active', true)
            ->where('customer_id', $customerId)
            ->count() > 1;
    }

    /**
     * Return the canonical components array used by this schema.
     * This allows other code (CreateAction forms, tests) to reuse the same
     * definition without duplicating the component tree.
     *
     * @return array
     */
    public static function schema(): array
    {
        return [
            // ---- Tenant-aware customer field ----
                Select::make('customer_id')
                    ->label('Customer')
                    // Persist the customer id when the field is hidden (tenant mode)
                    ->dehydratedWhenHidden()
                    ->live()
                    ->searchable()
                    ->preload()
                    // When in tenant mode default and hide the field
                    ->default(fn () => session('tenant_id'))
                    ->hidden(fn () => ! blank(session('tenant_id')))
                    ->nullable()
                    ->live()
                    ->searchable()
                    ->preload()
                    // When in tenant mode, default the filter to the tenant and hide the control
                    ->default(fn () => session('tenant_id'))
                    ->hidden(fn () => ! blank(session('tenant_id')))
                    ->options(function () {
                        return \App\Models\Customer::query()->orderBy('name')->pluck('name', 'id');
                    })
                    ->afterStateUpdated(fn ($set) => $set('location_filter_id', null)),

                Select::make('location_filter_id')
                    ->label('Filter by Location')
                    ->dehydrated(false)
                    ->live()
                    ->searchable()
                    ->preload()
                    ->options(function ($get) {
                        $query = \App\Models\Location::query();
                        if ($customerId = $get('customer_id')) {
                            $query->where('customer_id', $customerId);
                        }
                        return $query->pluck('name', 'id');
                    })
                    ->afterStateUpdated(fn ($set) => $set('rack_id', null)),

                // NOTE: removed legacy fields: `sections`, `type`, and `rack_size`. These are handled
                // by normalized relationships and the DeviceModel -> u_size value.
                // SNMP-related fields removed completely (sensitive / deprecated)
                

                Select::make('rack_id')
                    ->options(function ($get) {
                        $query = \App\Models\Rack::query();

                        // Attempt to scope using location filter or customer if provided
                        if ($locationId = $get('location_filter_id')) {
                            $query->where('location_id', $locationId);
                        } elseif ($customerId = $get('customer_id')) {
                            $query->where(function ($q) use ($customerId) {
                                $q->where('customer_id', $customerId)
                                  ->orWhereIn('location_id', function ($q2) use ($customerId) {
                                      $q2->from('locations')->select('id')->where('customer_id', $customerId);
                                  });
                            });
                        }

                        $defaultRackId = request()->query('rack_id');
                        if (! empty($defaultRackId)) {
                            $query->orWhere('id', $defaultRackId);
                        }

                        return $query->orderBy('name')->pluck('name', 'id');
                    })
                    ->searchable()
                    ->preload()
                    ->label('Rack')
                    ->default(request()->query('rack_id'))
                    ->createOptionForm(function ($get) {
                        return [
                            TextInput::make('name')->required(),
                            TextInput::make('units')->numeric()->default(42),
                            Select::make('customer_id')
                                ->options(function () {
                                    return \App\Models\Customer::query()->orderBy('name')->pluck('name', 'id');
                                })
                                ->default(fn () => $get('customer_id')),
                            Select::make('location_id')
                                ->options(function () {
                                    return \App\Models\Location::query()->orderBy('name')->pluck('name', 'id');
                                })
                                ->default(fn () => $get('location_filter_id') ?? $get('location_id')),
                            TextInput::make('description')->default(null),
                        ];
                    })
                    ->createOptionUsing(function (array $data): int {
                        $rack = \App\Models\Rack::create($data);
                        return $rack->getKey();
                    })
                    ->createOptionModalHeading('Create Rack'),

                // ---- Device details ----
                Select::make('device_model_id')
                    ->label('Hardware Model')
                    ->options(fn () => \App\Models\DeviceModel::query()->orderBy('brand')->orderBy('model')->get()->mapWithKeys(fn($r) => [$r->id => trim(sprintf('%s %s', $r->brand ?? '', $r->model ?? ''))])->toArray())
                    ->searchable()
                    ->preload()
                    ->required(fn ($get) => ! ($get('is_monitored') && (
                        $get('auth_driver') === 'mikrotik_rest'
                        && (filled($get('credential_profile_id')) || filled($get('auth_username')) || filled($get('auth_password')) || filled($get('auth_extra')))
                    )))
                    ->disabled(fn ($get, $record) => $record && $record->exists || (
                        $get('auth_driver') === 'mikrotik_rest'
                        && (filled($get('credential_profile_id')) || filled($get('auth_username')) || filled($get('auth_password')) || filled($get('auth_extra')))
                    ))
                    ->helperText('Locked when Mikrotik API credentials are provided — inventory will be pulled from the device. Required only if not auto-discovered. ')
                    ->createOptionForm([
                        TextInput::make('brand')->required(),
                        TextInput::make('model')->required(),
                        Select::make('type')
                            ->options(\App\Enums\DeviceType::class)
                            ->required(),
                        TextInput::make('u_size')
                            ->numeric()
                            ->default(1)
                            ->label('Size (U)'),
                        TextInput::make('ethernet_ports')
                            ->numeric()
                            ->default(0),
                        TextInput::make('sfp_ports')
                            ->numeric()
                            ->default(0),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        // Use a model helper so we can normalise enum values and keep logic testable
                        return \App\Models\DeviceModel::createFromQuickCreate($data);
                    })
                    ->createOptionModalHeading('Create Hardware Model')
                    ->live(),

                // Monitoring / auth
                \Filament\Forms\Components\Toggle::make('is_monitored')
                    ->label('Is Monitored')
                    ->default(true)
                    ->helperText('If disabled, the device will not be polled by agents.'),

                Select::make('scan_agent_id')
                    ->label('Remote Agent')
                    ->helperText('L’unico agent attivo del Cliente riceverà i jobs; scegli un agent globale se necessario.')
                    ->options(fn ($get) => self::agentOptions($get('customer_id') ?? null))
                    ->required(fn ($get) => self::hasMultipleAgents($get('customer_id') ?? null))
                    ->hint(fn ($get) => self::hasMultipleAgents($get('customer_id') ?? null)
                        ? 'Più agent attivi per questo cliente: seleziona quello corretto.'
                        : null)
                    ->hintColor('warning')
                    ->searchable()
                    ->preload()
                    ->nullable(),

                Select::make('credential_profile_id')
                    ->relationship('credentialProfile', 'name')
                    ->label('Credential Profile')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Prefer shared credentials; leave empty to use manual auth fields.'),

                Select::make('auth_driver')
                    ->label('Auth Driver')
                    ->options(['mikrotik_rest' => 'MikroTik REST', 'ssh' => 'SSH'])
                    ->default('mikrotik_rest'),

                TextInput::make('auth_username')
                    ->label('Username')
                    ->visible(fn ($get) => empty($get('credential_profile_id'))),

                TextInput::make('auth_password')
                    ->label('Password')
                    ->password()
                    ->visible(fn ($get) => empty($get('credential_profile_id'))),

                TextInput::make('auth_extra')
                    ->label('Auth JSON')
                    ->visible(fn ($get) => empty($get('credential_profile_id')))
                    ->helperText('Optional extra auth JSON for manual mode.'),

                TextInput::make('hostname')
                    ->required(fn ($get) => ! $get('is_monitored'))
                    ->disabled(fn ($get, $record) => $record && $record->exists && $get('is_monitored'))
                    ->helperText('When monitoring is enabled, hostname will be fetched from the device identity.'),

                TextInput::make('management_ip')
                    ->label('Management IP')
                    ->helperText('Authoritative management address used for discovery according to the PDL.')
                    ->default(null)
                    ->nullable()
                    ->rules(['ipv4']),

                TextInput::make('ip_addr')
                    ->label('IP Address')
                    ->helperText('IPv4 address used by the agent to reach the device (recommended when monitoring)')
                    ->default(null)
                    ->nullable()
                    ->rules(['ipv4']),

                TextInput::make('rack_position')
                    ->default(request()->query('rack_position'))
                    ->numeric()
                    ->label('Rack Unit (U)')
                    ->minValue(1)
                    ->maxValue(52)
                    ->disabled(fn ($get) => filled($get('rack_position'))),
                // Keep a proper persisted location field if the Device model expects it; keep it after the details
                    Select::make('location_id')
                    ->relationship(
                        name: 'location',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (\Illuminate\Database\Eloquent\Builder $query, $get) => 
                            $query->when($get('location_filter_id'), fn ($q, $id) => $q->where('id', $id))
                                  ->when($get('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
                    )
                    ->searchable()
                    ->preload()
                    ->default(null)
                    ->disabled(fn ($get) => ! ($get('customer_id') || $get('location_filter_id'))),

                // ---- SNMP Settings (Fase 1, per-device) ----
                Group::make()
                    ->relationship('snmpSettings')
                    ->schema([
                        Fieldset::make('SNMP Settings')
                            ->schema([
                                \Filament\Forms\Components\Toggle::make('enabled')
                                    ->label('Enable SNMP')
                                    ->helperText('Enable SNMP polling for this device. Fase 1 uses SNMPv1/v2c only.')
                                    ->default(false),
                                TextInput::make('host')
                                    ->label('SNMP Host / IP')
                                    ->helperText('IP or hostname of the SNMP endpoint. For UniFi, this is the controller address.')
                                    ->nullable(),
                                TextInput::make('port')
                                    ->label('Port')
                                    ->numeric()
                                    ->default(161),
                                Select::make('version')
                                    ->label('Version')
                                    ->options([
                                        'v1' => 'SNMPv1',
                                        'v2c' => 'SNMPv2c',
                                    ])
                                    ->default('v2c'),
                                TextInput::make('community')
                                    ->label('Community')
                                    ->password()
                                    ->revealable()
                                    ->helperText('Community string, stored encrypted. SNMPv3 is out of scope for Fase 1.')
                                    ->nullable(),
                                TextInput::make('timeout')
                                    ->label('Timeout (ms)')
                                    ->numeric()
                                    ->default(3000),
                                TextInput::make('retries')
                                    ->label('Retries')
                                    ->numeric()
                                    ->default(1),
                                Repeater::make('port_name_priority')
                                    ->label('Port Name Priority')
                                    ->helperText('Drag to reorder how SNMP port names are resolved (ifAlias / ifDescr / ifName).')
                                    ->schema([
                                        TextInput::make('value')
                                            ->label('Field')
                                            ->disabled()
                                            ->dehydrated(true),
                                    ])
                                    ->reorderable()
                                    ->addable(false)
                                    ->deletable(false)
                                    ->columnSpanFull()
                                    ->default([
                                        ['value' => 'ifAlias'],
                                        ['value' => 'ifDescr'],
                                        ['value' => 'ifName'],
                                    ])
                                    ->afterStateHydrated(function (Repeater $component, $state): void {
                                        if (empty($state)) {
                                            $component->state([
                                                ['value' => 'ifAlias'],
                                                ['value' => 'ifDescr'],
                                                ['value' => 'ifName'],
                                            ]);

                                            return;
                                        }

                                        // Map plain array of strings → array of ['value' => string] for the UI
                                        if (is_array($state) && isset($state[0]) && ! is_array($state[0])) {
                                            $component->state(
                                                collect($state)->map(fn ($value) => ['value' => $value])->all()
                                            );
                                        }
                                    })
                                    ->dehydrateStateUsing(fn (?array $state): array => collect($state ?? [])->pluck('value')->all()),
                                Repeater::make('custom_oids')
                                    ->label('Custom global OIDs')
                                    ->schema([
                                        TextInput::make('oid')
                                            ->label('OID')
                                            ->required(),
                                        TextInput::make('name')
                                            ->label('Field name')
                                            ->required(),
                                        Select::make('expected_type')
                                            ->label('Expected type')
                                            ->options([
                                                'string' => 'String',
                                                'int' => 'Integer',
                                                'gauge' => 'Gauge',
                                                'boolean' => 'Boolean',
                                            ])
                                            ->required(),
                                    ])
                                    ->columns(3)
                                    ->collapsed()
                                    ->helperText('Device-level custom OIDs. Per-port OIDs, LLDP, PoE and vendor-specific MIBs are out of scope for Fase 1.'),
                            ]),
                    ])
                    ->columnSpanFull(),
                    ];
    }

                public static function configure(Schema $schema): Schema
                {
                return $schema->components(self::schema());
                }
}

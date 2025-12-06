<?php

namespace App\Filament\Clusters\Racks\Resources\Devices\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class DeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // ---- Location filters (virtual helpers - not persisted on Device) ----
                Select::make('customer_id')
                    ->label('Filter by Customer')
                    ->dehydrated(false)
                    ->live()
                    ->searchable()
                    ->preload()
                    // When in tenant mode, default the filter to the tenant and hide the control
                    ->default(fn () => session('tenant_id'))
                    ->hidden(fn () => ! blank(session('tenant_id')))
                    ->options(function () {
                        return \App\Models\Customer::query()->pluck('name', 'id');
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
                    ->relationship('rack', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Rack')
                    ->default(request()->query('rack_id'))
                    ->createOptionForm(function ($get) {
                        return [
                            TextInput::make('name')->required(),
                            TextInput::make('units')->numeric()->default(42),
                            Select::make('customer_id')
                                ->relationship('customer', 'name')
                                ->default(fn () => $get('customer_id')),
                            Select::make('location_id')
                                ->relationship('location', 'name')
                                ->default(fn () => $get('location_filter_id') ?? $get('location_id')),
                            TextInput::make('description')->default(null),
                        ];
                    })
                    ->createOptionUsing(function (array $data): int {
                        $rack = \App\Models\Rack::create($data);
                        return $rack->getKey();
                    })
                    ->createOptionModalHeading('Create Rack')
                    ->required()
                    ->options(function ($get) {
                        $query = \App\Models\Rack::query();

                        // keep an explicit location filter if provided by the UI
                        $defaultRackId = request()->query('rack_id');

                        if ($locationId = $get('location_filter_id')) {
                            $query->where('location_id', $locationId);
                        } elseif ($customerId = $get('customer_id')) {
                            // include racks that belong directly to the selected customer
                            // OR racks whose location belongs to the selected customer
                            $query->where(function ($q) use ($customerId) {
                                $q->where('customer_id', $customerId)
                                  ->orWhereIn('location_id', function ($q2) use ($customerId) {
                                      $q2->from('locations')->select('id')->where('customer_id', $customerId);
                                  });
                            });
                        }

                        // ensure the default rack_id passed via request remains selectable
                        if (! empty($defaultRackId)) {
                            $query->orWhere('id', $defaultRackId);
                        }

                        return $query->pluck('name', 'id');
                    }),

                // ---- Device details ----
                Select::make('device_model_id')
                    ->label('Hardware Model')
                    ->relationship('deviceModel')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => trim(sprintf('%s %s', $record->brand ?? '', $record->model ?? '')))
                    ->searchable(['brand', 'model'])
                    ->preload()
                    ->required()
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

                TextInput::make('hostname')
                    ->required(),

                TextInput::make('ip_addr')
                    ->label('IP Address')
                    ->helperText('Optional — IPv4 format')
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
            ]);
    }
}

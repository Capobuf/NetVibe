<?php

namespace App\Filament\Clusters\Racks\Resources\Racks;

use App\Filament\Clusters\Racks\RacksCluster;
use App\Filament\Clusters\Racks\Resources\Devices\DeviceResource;
use App\Filament\Clusters\Racks\Resources\Racks\Pages\CreateRack;
use App\Filament\Clusters\Racks\Resources\Racks\Pages\EditRack;
use App\Filament\Clusters\Racks\Resources\Racks\Pages\ListRacks;
use App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack;
use App\Filament\Clusters\Racks\Resources\Racks\Schemas\RackForm;
use App\Filament\Clusters\Racks\Resources\Racks\Tables\RacksTable;
use App\Models\Device;
use App\Models\Link;
use App\Models\Port;
use App\Models\Rack;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Support\Enums\Alignment;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RackResource extends Resource
{
    protected static ?string $model = Rack::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServer;
    
    protected static ?string $cluster = RacksCluster::class;
    
    protected static ?int $navigationSort = 5;

    private const TOTAL_UNITS = 42;

    public static function form(Schema $schema): Schema
    {
        return RackForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RacksTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Rack Visualization')
                ->description(fn (Rack $record): string => $record->name ? "{$record->name} · Rack View" : 'Rack View')
                ->schema([
                    Grid::make(1)
                        ->schema(fn (Rack $record): array => self::buildRackUnitComponents($record))
                        ->extraAttributes(['style' => 'gap: 0;']), 
                ])
                ->compact(),
        ]);
    }

    public static function buildRackUnitComponents(Rack $rack): array
    {
        $units = self::buildUnits($rack);
        $components = [];

        // Header
        $components[] = self::makeHeaderRow();

        foreach ($units as $unit => $slot) {
            if ($slot['type'] === 'spanned') {
                continue;
            }

            if ($slot['type'] === 'device') {
                $components[] = self::makeDeviceRow($rack, $unit, $slot['device'], $slot['span'] ?? 1);
                continue;
            }

            if ($slot['type'] === 'ghost') {
                $components[] = self::makeGhostRow($rack, $unit, $slot['device']);
                continue;
            }

            $components[] = self::makeEmptySlotRow($rack, $unit);
        }

        return $components;
    }

    private static function makeHeaderRow(): Flex
    {
        return Flex::make([
            Group::make()
                ->schema([
                    TextEntry::make('header_u')
                        ->state('U')
                        ->weight('bold')
                        ->alignCenter()
                        ->hiddenLabel(),
                ])
                ->grow(false)
                ->extraAttributes([
                    'style' => 'width: 50px; min-width: 50px; display: flex; align-items: center; justify-content: center; border-right: 1px solid rgba(255,255,255,0.1); padding: 0.25rem;',
                ]),

            Group::make()
                ->schema([
                    TextEntry::make('header_content')
                        ->state('Device Configuration')
                        ->weight('bold')
                        ->hiddenLabel(),
                ])
                ->grow(true)
                ->extraAttributes([
                    'style' => 'padding: 0.25rem 0.5rem; display: flex; align-items: center;',
                ]),
        ])
        ->extraAttributes([
            'class' => 'border-b border-gray-700',
            'style' => 'align-items: stretch; background-color: rgba(255,255,255,0.05);',
        ]);
    }

    /**
     * Helper factory to reduce duplication for the U label entry used across multiple rows.
     */
    private static function makeUnitLabelEntry(Rack $rack, int $unit, bool $badge = true, ?string $size = null, array $extraAttributes = []): TextEntry
    {
        $entry = TextEntry::make("rack_{$rack->id}_u{$unit}_label")
            ->state($unit)
            ->color('gray')
            ->alignCenter()
            ->hiddenLabel();

        if ($badge) {
            $entry = $entry->badge();
        }

        if ($size) {
            $entry = $entry->size($size);
        }

        if (! empty($extraAttributes)) {
            $entry = $entry->extraAttributes($extraAttributes);
        }

        return $entry;
    }

    private static function makeUnitLeftColumn(Rack $rack, int $unit, TextEntry $entry): Group
    {
        return Group::make()
            ->schema([$entry])
            ->grow(false)
            ->extraAttributes([
                'style' => 'width: 50px; min-width: 50px; display: flex; align-items: center; justify-content: center; border-right: 1px solid rgba(255,255,255,0.1); padding: 0;',
            ]);
    }

    private static function makeDeviceRow(Rack $rack, int $unit, Device $device, int $span = 1): Flex
    {
        $editUrl = DeviceResource::getUrl('edit', ['record' => $device->id]);
        $hostname = $device->hostname ?? $device->name;
        $model = $device->deviceModel?->name ?? 'Unknown';

        $portEntries = $device->ports
            ->map(fn (Port $port): TextEntry => self::makePortActionEntry($rack, $unit, $device, $port))
            ->all();

        return Flex::make([
            // COLONNA U (Sinistra)
            self::makeUnitLeftColumn($rack, $unit, self::makeUnitLabelEntry($rack, $unit, badge: true)),

            // COLONNA DEVICE (Destra)
            Group::make()
                ->schema([
                    // Icona Info (Dettagli + Edit)
                    SchemaActions::make([
                        Action::make("info-device-{$unit}-{$device->id}")
                            ->icon(Heroicon::InformationCircle)
                            ->iconButton()
                            ->color('gray')
                            ->size('xs')
                            ->tooltip("{$hostname} ({$model})")
                            ->modalHeading("Device: {$hostname}")
                            ->form([
                                Placeholder::make('overview')
                                    ->label('Overview')
                                    ->content("Model: {$model} | Size: {$span}U"),
                            ])
                            ->extraModalFooterActions([
                                Action::make('edit')
                                    ->label('Edit Device')
                                    ->url($editUrl)
                                    ->button()
                                    ->color('primary'),
                            ]),
                    ])->alignment(Alignment::End)->extraAttributes(['style' => 'position: absolute; top: 2px; right: 2px; z-index: 10; opacity: 0.5;']),

                    // FACEPLATE GRID
                    Grid::make(12)
                        ->schema($portEntries)
                        ->extraAttributes([
                            'style' => 'display: grid; grid-template-columns: repeat(12, 1fr); gap: 3px; align-items: center; width: 100%; padding-right: 24px;', 
                        ]),
                ])
                ->grow(true)
                ->extraAttributes([
                    'style' => 'padding: 0.5rem; position: relative;', 
                ]),
        ])
        ->extraAttributes([
            'class' => 'border-b border-gray-700',
            'style' => 'align-items: stretch; background-color: rgba(255,255,255,0.02);', 
        ]);
    }

private static function makeEmptySlotRow(Rack $rack, int $unit): Flex
    {
        $createAction = Action::make("create-device-{$rack->id}-{$unit}")
            ->label('Add Device')
            ->icon('heroicon-m-plus')
            ->iconButton()
            ->size('sm')
            ->tooltip("Install Device in U{$unit}")
            ->color('success')
            ->slideOver()
            ->modalHeading("Install Device (U{$unit})")
            ->modalWidth('md')
            ->form([
                // Usa options() con query diretta invece di relationship() per evitare l'errore sul model Rack
                Select::make('device_model_id')
                    ->label('Device Model')
                    // DeviceModel provides a virtual `name` attribute (brand + model) via accessor.
                    // When using a query builder, pluck('name') will try to select a DB column 'name'
                    // which doesn't exist. Use get()->pluck(...) so the accessor is evaluated on the
                    // retrieved models instead of in SQL.
                    ->options(fn () => \App\Models\DeviceModel::query()->get()->pluck('name', 'id'))
                    ->required()
                    ->searchable()
                    ->preload(),

                \Filament\Forms\Components\TextInput::make('hostname')
                    ->label('Hostname')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. SW-CORE-01'),

                \Filament\Forms\Components\TextInput::make('serial_number')
                    ->label('Serial Number')
                    ->maxLength(255),

                \Filament\Forms\Components\Hidden::make('rack_id')
                    ->default($rack->id),
                
                \Filament\Forms\Components\Hidden::make('rack_position')
                    ->default($unit),
                
                \Filament\Forms\Components\Hidden::make('status')
                    ->default('active'),
            ])
            ->action(function (array $data) {
                Device::create($data);

                \Filament\Notifications\Notification::make()
                    ->title('Device installed successfully')
                    ->success()
                    ->send();
            });

        return Flex::make([
            self::makeUnitLeftColumn($rack, $unit, self::makeUnitLabelEntry($rack, $unit, badge: true, size: 'xs', extraAttributes: ['class' => 'opacity-50'])),

            Group::make()
                ->schema([
                    SchemaActions::make([$createAction])
                        ->alignment(Alignment::Center),
                ])
                ->grow(true)
                ->extraAttributes([
                    'style' => 'padding: 0; display: flex; align-items: center; justify-content: center;',
                ]),
        ])
        ->extraAttributes([
            'class' => 'border-b border-gray-800',
            'style' => 'align-items: stretch; min-height: 32px;',
        ]);
    }

    private static function makeGhostRow(Rack $rack, int $unit, Device $device): Flex
    {
        return Flex::make([
            self::makeUnitLeftColumn($rack, $unit, self::makeUnitLabelEntry($rack, $unit, badge: true)),

            Group::make()
                ->schema([])
                ->grow(true)
                ->extraAttributes([
                    'style' => 'background-color: rgba(255,255,255,0.02);',
                ]),
        ])
        ->extraAttributes([
            'class' => 'border-b border-gray-700',
            'style' => 'align-items: stretch;',
        ]);
    }

    private static function makePortActionEntry(Rack $rack, int $unit, Device $device, Port $port): TextEntry
    {
        $isActive = $port->status === 'active';
        $link = null;
        $connectedPort = null;

        if ($isActive) {
            $link = Link::with(['cableType', 'portA.device', 'portB.device'])
                ->where(fn ($query) => $query
                    ->where('port_a_id', $port->id)
                    ->orWhere('port_b_id', $port->id))
                ->first();

            if ($link) {
                $connectedPort = $link->portA_id === $port->id ? $link->portB : $link->portA;
            }
        }

        $connectAction = Action::make("connect-port-{$port->id}")
            ->label($port->name ?? 'Port')
            ->color(self::portStatusColor($port->status))
            ->modalHeading('Connect port')
            ->modalWidth('lg')
            ->form([
                Select::make('target_device_id')
                    ->label('Target device')
                    ->searchable()
                    ->options(fn () => Device::query()
                        ->where('id', '!=', $device->id)
                        ->orderBy('hostname')
                        ->pluck('hostname', 'id'))
                    ->reactive()
                    ->afterStateUpdated(fn (callable $set) => $set('target_port_id', null))
                    ->required(),

                Select::make('target_port_id')
                    ->label('Target port')
                    ->options(fn (Closure $get) => Port::query()
                        ->where('device_id', $get('target_device_id'))
                        ->where('status', '!=', 'active')
                        ->pluck('name', 'id'))
                    ->required(),

                Select::make('cable_type_id')
                    ->label('Cable type')
                    ->relationship('cableType', 'name')
                    ->required(),

                ColorPicker::make('color')
                    ->label('Cable color')
                    ->nullable(),
            ])
            ->action(function (array $data) use ($port): void {
                Link::create([
                    'port_a_id' => $port->id,
                    'port_b_id' => $data['target_port_id'],
                    'cable_type_id' => $data['cable_type_id'],
                    'color' => $data['color'],
                ]);

                $targetPort = Port::find($data['target_port_id']);
                $port->update(['status' => 'active']);
                $targetPort?->update(['status' => 'active']);
                $port->refresh();
                $targetPort?->refresh();
            });

        $disconnectAction = Action::make("disconnect-port-{$port->id}")
            ->label($port->name ?? 'Port')
            ->icon(Heroicon::Link)
            ->color('danger')
            ->modalHeading('Link details')
            ->modalSubheading(fn () => $link ? "{$link->cableType?->name} · {$link->color}" : 'Linked port')
            ->form([
                Placeholder::make('details')
                    ->label('Connected to')
                    ->content(fn () => $connectedPort
                        ? "{$connectedPort->device->hostname} · {$connectedPort->name}"
                        : 'Unknown'),
            ])
            ->action(function () use ($link, $port, $connectedPort): void {
                if ($link) {
                    $link->delete();
                }

                $connectedPort?->update(['status' => 'free']);
                $port->update(['status' => 'free']);
                $port->refresh();
                $connectedPort?->refresh();
            });

        $action = $isActive ? $disconnectAction : $connectAction;

        $baseStyle = implode(' ', [
            "border: 1px solid #4b5563;",
            "background-color: #1f2937;",
            "border-radius: 2px;",
            "font-size: 0.65rem;",
            "font-family: monospace;",
            "height: 26px;",
            "display: flex;",
            "align-items: center;",
            "justify-content: center;",
            "cursor: pointer;",
            "user-select: none;",
            "transition: all 0.15s ease-in-out;",
            "overflow: hidden;",
        ]);

        $activeStyle = $isActive 
            ? 'background-color: rgba(6, 78, 59, 0.9); border-color: #10b981; color: #dcfce7; box-shadow: 0 0 4px rgba(16, 185, 129, 0.4);' 
            : 'color: #9ca3af; hover:border-gray-400 hover:bg-gray-700';

        return TextEntry::make("rack_{$rack->id}_u{$unit}_port_{$port->id}")
            ->hiddenLabel()
            ->state($port->name ?? null)
            ->tooltip($port->name . ($isActive ? " (Linked)" : ""))
            ->extraAttributes([
                'style' => $baseStyle . ' ' . $activeStyle,
                'data-port-id' => $port->id,
            ])
            ->action($action);
    }

    public static function buildUnits(Rack $rack): array
    {
        $devices = Device::query()
            ->where('rack_id', $rack->id)
            ->with(['deviceModel', 'ports'])
            ->get();

        $totalUnits = (int) ($rack->units ?? self::TOTAL_UNITS);
        $occupied = array_fill(1, $totalUnits, null);

        foreach ($devices as $device) {
            $position = (int) ($device->rack_position ?? 0);
            if ($position < 1 || $position > $totalUnits) {
                continue;
            }

            $size = (int) ($device->deviceModel?->u_size ?? 1);

            for ($offset = 0; $offset < $size; $offset++) {
                $unit = $position + $offset;

                if ($unit > $totalUnits) {
                    break;
                }

                $occupied[$unit] = [
                    'device' => $device,
                    'start' => $offset === 0,
                    'span' => $size,
                ];
            }
        }

        $units = [];

        for ($unit = $totalUnits; $unit >= 1; $unit--) {
            $slot = $occupied[$unit] ?? null;

            if ($slot === null) {
                $units[$unit] = ['type' => 'empty'];
                continue;
            }

            if ($slot['start']) {
                $units[$unit] = [
                    'type' => 'device',
                    'device' => $slot['device'],
                    'span' => $slot['span'],
                ];
                continue;
            }

            $units[$unit] = ['type' => 'ghost', 'device' => $slot['device']];
        }

        return $units;
    }

    private static function portStatusLabel(?string $status): string
    {
        return match ($status) {
            'active' => 'Connected',
            'error' => 'Error',
            default => 'Available',
        };
    }

    private static function portStatusColor(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'error' => 'danger',
            default => 'gray',
        };
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Clusters\Racks\Resources\Racks\RelationManagers\DevicesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRacks::route('/'),
            'create' => CreateRack::route('/create'),
            'view' => ViewRack::route('/{record}'),
            'edit' => EditRack::route('/{record}/edit'),
        ];
    }
}
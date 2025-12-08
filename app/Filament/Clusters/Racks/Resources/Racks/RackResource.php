<?php

namespace App\Filament\Clusters\Racks\Resources\Racks;

use App\Filament\Clusters\Racks\RacksCluster;
use App\Filament\Clusters\Racks\Resources\Racks\Pages\CreateRack;
use App\Filament\Clusters\Racks\Resources\Racks\Pages\EditRack;
use App\Filament\Clusters\Racks\Resources\Racks\Pages\ListRacks;
use App\Filament\Clusters\Racks\Resources\Racks\Pages\ViewRack;
use App\Filament\Clusters\Racks\Resources\Racks\Schemas\RackForm;
use App\Filament\Clusters\Racks\Resources\Racks\Tables\RacksTable;
use App\Models\Device;
use App\Models\Rack;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Get as FormGet;
use Filament\Schemas\Components\Utilities\Get as SchemaGet;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Alignment;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class RackResource extends Resource
{
    protected static ?string $model = Rack::class;

    protected static string|BackedEnum|null $navigationIcon = null; // cluster provides icon

    // Detached from cluster to allow grouped navigation under 'Racks & Devices'
    // protected static ?string $cluster = RacksCluster::class;

    // Ensure the resource appears under Racks & Devices dropdown
    protected static string | \UnitEnum | null $navigationGroup = 'Racks & Devices';

    protected static ?int $navigationSort = 5;

    private const TOTAL_UNITS = 42;

    /**
     * Backwards-compatible static helper used in tests / other places that
     * relied on RackResource::buildUnits(). Compute the layout for a given
     * Rack without instantiating Livewire/Widget classes.
     *
     * @return array<int, array>
     */
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
                if ($unit > $totalUnits) break;

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
                $units[$unit] = ['type' => 'device', 'device' => $slot['device'], 'span' => $slot['span']];
                continue;
            }
            $units[$unit] = ['type' => 'ghost', 'device' => $slot['device']];
        }

        return $units;
    }

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
        // The visual rack UI is provided by a Filament widget on the
        // View page. We keep the infolist configuration on the resource
        // minimal to avoid mounting Livewire/Blade artifacts here.
        return $schema->schema([]);
    }

    // buildUnits moved to RackVisualizer widget so the visual and actions are
    // co-located. Keep the resource focused on configuration and relations.

    public static function getRelations(): array
    {
        return [
            \App\Filament\Clusters\Racks\Resources\Racks\RelationManagers\DevicesRelationManager::class,
        ];
    }

    /**
     * Register per-slot action schemas (create/mount) for the Rack page.
     * This is kept on the Resource for discoverability by the tests.
     * Note: the schema generation is a pure factory and does not run the
     * Livewire lifecycle.
     */
    // All rendering logic moved to the Filament widget. Per-slot action
    // registration for tests is handled via the widget's static helper
    // (`RackVisualizerWidget::buildSlotActionSchemasFor`).

    // Slot actions are now encapsulated in the RackVisualizer widget.

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
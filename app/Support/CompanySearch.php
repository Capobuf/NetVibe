<?php

namespace App\Support;

use App\Models\Cable;
use App\Models\CableEnd;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Rack;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Company-scoped text search over inventory and cabling, shared by the header suggestions and the results page. */
class CompanySearch
{
    private string $pattern;

    /** @var Collection<int, string|null> */
    private Collection $sites;

    public function __construct(private Company $company, string $search)
    {
        $this->pattern = self::pattern($search);
        $this->sites = $company->sites()->pluck('name', 'id');
    }

    public static function pattern(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }

    /** @return Collection<int, string|null> */
    public function sites(): Collection
    {
        return $this->sites;
    }

    /** @return array{total: int, rows: Collection<int, array<string, mixed>>} */
    public function devices(?int $limit = null): array
    {
        $query = Device::whereIn('site_id', $this->sites->keys())
            ->where(fn ($query) => $this->like($query, ['name', 'serial_number', 'asset_tag']))
            ->with('manufacturer:id,name', 'model:id,name', 'rackItem.rack:id,name')->orderBy('name');

        return $this->result($query, $limit, fn (Device $device) => [
            'id' => $device->id, 'name' => $device->name,
            'manufacturer' => $device->manufacturer?->name, 'model' => $device->model?->name,
            'rack' => $device->rackItem?->rack?->name, 'site' => $this->sites->get($device->site_id),
            'serial_number' => $device->serial_number, 'site_id' => $device->site_id,
            'href' => route('companies.sites.devices.show', [$this->company->id, $device->site_id, $device->id], false),
        ]);
    }

    /** @return array{total: int, rows: Collection<int, array<string, mixed>>} */
    public function models(?int $limit = null): array
    {
        $query = DeviceModel::with('manufacturer:id,name', 'modelType:id,name')
            ->withCount(['devices' => fn ($query) => $query->whereIn('site_id', $this->sites->keys())])
            ->where(fn ($query) => $this->like($query, ['name'])
                ->orWhereHas('manufacturer', fn ($query) => $this->like($query, ['name'])))
            ->orderBy('name');

        return $this->result($query, $limit, fn (DeviceModel $model) => [
            'id' => $model->id, 'name' => $model->name,
            'manufacturer' => $model->manufacturer?->name, 'type' => $model->modelType?->name,
            'devices_count' => $model->devices_count,
            'href' => route('device-models.index', ['model' => $model->id], false),
        ]);
    }

    /** @return array{total: int, rows: Collection<int, array<string, mixed>>} */
    public function racks(?int $limit = null): array
    {
        $query = Rack::whereIn('site_id', $this->sites->keys())
            ->where(fn ($query) => $this->like($query, ['name', 'floor']))
            ->withCount(['items as devices_count' => fn ($query) => $query->whereNotNull('device_id')])->orderBy('name');

        return $this->result($query, $limit, fn (Rack $rack) => [
            'id' => $rack->id, 'name' => $rack->name, 'floor' => $rack->floor,
            'site' => $this->sites->get($rack->site_id), 'site_id' => $rack->site_id, 'devices_count' => $rack->devices_count,
            'href' => route('companies.sites.racks.show', [$this->company->id, $rack->site_id, $rack->id], false),
        ]);
    }

    /**
     * Cables match on code, name, purpose or the name of a connected device.
     *
     * @return array{total: int, rows: Collection<int, array<string, mixed>>}
     */
    public function cables(?int $limit = null): array
    {
        $query = Cable::whereIn('site_id', $this->sites->keys())
            ->where(fn ($query) => $this->like($query, ['name', 'purpose'])
                ->orWhereRaw("CONCAT('C-', cables.id) LIKE ? ESCAPE '!'", [$this->pattern])
                ->orWhereIn('id', CableEnd::join('devices', 'devices.id', '=', 'cable_ends.device_id')
                    ->whereRaw("devices.name LIKE ? ESCAPE '!'", [$this->pattern])->select('cable_ends.cable_id')))
            ->with('ends')->orderBy('id');
        $result = $this->result($query, $limit, fn (Cable $cable) => $cable);
        $devices = Device::whereIn('id', $result['rows']->flatMap(fn (Cable $cable) => $cable->ends->pluck('device_id')))
            ->with(['model:id,panel', 'interfaces:id,device_id,connector_id,name', 'rackItem.rack:id,name'])->get()->keyBy('id');
        $end = function (?CableEnd $end) use ($devices) {
            $device = $devices->get($end?->device_id);
            $port = $device?->interfaces->firstWhere('connector_id', $end->connector_id)?->name
                ?: collect(['front', 'rear'])->flatMap(fn ($face) => $device?->model?->panel['faces'][$face]['ports'] ?? [])
                    ->firstWhere('id', $end?->connector_id)['label'] ?? null;

            return ($device?->name ?? 'Dispositivo rimosso').'@'.($port ?: substr((string) $end?->connector_id, 0, 8));
        };
        $result['rows'] = $result['rows']->map(function (Cable $cable) use ($devices, $end) {
            $ends = $cable->ends->keyBy('side');
            $rack = collect(['a', 'b'])->map(fn ($side) => $devices->get($ends->get($side)?->device_id)?->rackItem?->rack)->filter()->first();
            $site = [$this->company->id, $cable->site_id];

            return [
                'id' => $cable->id, 'code' => "C-{$cable->id}", 'name' => $cable->name, 'type' => $cable->type,
                'purpose' => $cable->purpose, 'a' => $end($ends->get('a')), 'b' => $end($ends->get('b')),
                'site' => $this->sites->get($cable->site_id), 'site_id' => $cable->site_id,
                'href' => $rack ? route('companies.sites.racks.show', [...$site, $rack->id, 'cable' => $cable->id], false)
                    : route('companies.sites.racks.index', $site, false),
            ];
        });

        return $result;
    }

    /**
     * Adds "any of these columns matches" as one grouped condition.
     *
     * @param  list<string>  $columns
     */
    private function like(Builder $query, array $columns): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $query) use ($table, $columns) {
            foreach ($columns as $column) {
                $query->orWhereRaw("{$table}.{$column} LIKE ? ESCAPE '!'", [$this->pattern]);
            }
        });
    }

    /** @return array{total: int, rows: Collection<int, mixed>} */
    private function result(Builder $query, ?int $limit, callable $map): array
    {
        $total = $limit === null ? null : (clone $query)->count();
        $rows = $query->when($limit, fn ($query) => $query->limit($limit))->get()->map($map);

        return ['total' => $total ?? $rows->count(), 'rows' => $rows];
    }
}

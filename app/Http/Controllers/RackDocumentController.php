<?php

namespace App\Http\Controllers;

use App\Models\Cable;
use App\Models\Company;
use App\Models\Rack;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RackDocumentController extends Controller
{
    public function show(Company $company, Site $site, ?Rack $rack = null): Response
    {
        return DB::transaction(function () use ($company, $site, $rack) {
            $racks = $site->racks()->when($rack, fn ($query) => $query->whereKey($rack->id))
                ->with('items')->orderBy('name')->orderBy('id')->get();
            $mountedIds = $racks->flatMap->items->pluck('device_id')->filter()->unique();
            $cables = Cable::query()->where('site_id', $site->id)
                ->whereHas('ends', fn ($query) => $query->whereIn('device_id', $mountedIds))
                ->with('ends')->orderBy('id')->get();
            $deviceIds = $mountedIds->merge($cables->flatMap->ends->pluck('device_id'))->unique();
            $devices = $site->devices()->whereKey($deviceIds)->with([
                'model', 'manufacturer:id,name', 'rackItem.rack.items',
                'interfaces' => fn ($query) => $query->without('mikrotikSource'),
            ])->orderBy('name')->orderBy('id')->get()->keyBy('id');

            return Inertia::render('racks/documentation', [
                'company' => $company->only('id', 'name'),
                'site' => $site->only('id', 'name'),
                'rack' => $rack?->only('id', 'name'),
                'document' => [
                    'generatedAt' => now()->toIso8601String(),
                    'racks' => $racks->map(fn ($item) => [
                        ...$item->only('id', 'name', 'height_u', 'numbering'),
                        'items' => $item->items->map->only(['key', 'kind', 'device_id', 'shelf_key', 'position', 'height', 'width', 'x']),
                    ]),
                    'devices' => $devices->map(function ($device) {
                        $placement = $device->rackItem;
                        $supports = collect();
                        $parent = $placement;
                        while ($parent?->shelf_key && ! $supports->contains('key', $parent->shelf_key)) {
                            $parent = $placement->rack->items->firstWhere('key', $parent->shelf_key);
                            if ($parent) {
                                $supports->push($parent);
                            }
                        }

                        return [
                            ...$device->only('id', 'name', 'serial_number', 'asset_tag'),
                            'manufacturer' => $device->manufacturer?->name,
                            'model' => $device->model,
                            'assetUrls' => $device->model?->assetUrls() ?? (object) [],
                            'rack' => $device->rackItem?->rack?->only('id', 'name', 'height_u', 'numbering'),
                            'placement' => $placement?->only('key', 'kind', 'device_id', 'shelf_key', 'position', 'height', 'width', 'x'),
                            'supports' => $supports->map->only(['key', 'kind', 'device_id', 'shelf_key', 'position', 'height', 'width', 'x']),
                        ];
                    })->values(),
                    'cables' => $cables->map(function ($cable) use ($devices) {
                        return [
                            ...$cable->only('id', 'name', 'type', 'color', 'purpose'),
                            ...$cable->ends->mapWithKeys(function ($end) use ($devices) {
                                $device = $devices->get($end->device_id);
                                $port = collect($device?->model?->connectors() ?? [])->firstWhere('id', $end->connector_id);
                                $interface = $device?->interfaces->firstWhere('connector_id', $end->connector_id);

                                return [$end->side => [
                                    'device_id' => $device?->id,
                                    'device' => $device?->name,
                                    'port' => $interface?->name ?: ($port['label'] ?? null),
                                ]];
                            })->all(),
                        ];
                    }),
                ],
            ]);
        });
    }
}

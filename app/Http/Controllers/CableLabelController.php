<?php

namespace App\Http\Controllers;

use App\Models\Cable;
use App\Models\CableLabelTemplate;
use App\Models\Company;
use App\Models\Rack;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CableLabelController extends Controller
{
    public function show(Company $company, Site $site, Rack $rack, int $cableId): Response
    {
        return DB::transaction(function () use ($company, $site, $rack, $cableId) {
            $cable = Cable::query()->where('site_id', $site->id)->with('ends')->findOrFail($cableId);
            abort_unless($rack->items()->whereIn('device_id', $cable->ends->pluck('device_id'))->exists(), 404);
            $devices = $site->devices()->whereKey($cable->ends->pluck('device_id'))
                ->with(['model', 'interfaces', 'rackItem.rack'])->get()->keyBy('id');
            $ends = $cable->ends->mapWithKeys(function ($end) use ($devices) {
                $device = $devices->get($end->device_id);
                $port = collect($device?->model?->connectors() ?? [])->firstWhere('id', $end->connector_id);
                $interface = $device?->interfaces->firstWhere('connector_id', $end->connector_id);

                return [$end->side => [
                    'piano' => $device?->rackItem?->rack?->floor,
                    'rack' => $device?->rackItem?->rack?->name,
                    'dispositivo' => $device?->name,
                    'porta' => $interface?->name ?: ($port['label'] ?? null),
                ]];
            });

            return Inertia::render('racks/cable-labels', [
                'company' => $company->only('id', 'name'),
                'site' => $site->only('id', 'name'),
                'rack' => $rack->only('id', 'name'),
                'cable' => $cable->only('name', 'type', 'color'),
                'ends' => $ends,
                'templates' => CableLabelTemplate::query()->where('cable_type', $cable->type)->get(),
            ]);
        });
    }
}

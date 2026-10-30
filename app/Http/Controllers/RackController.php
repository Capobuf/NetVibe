<?php

namespace App\Http\Controllers;

use App\Http\Requests\RackRequest;
use App\Models\Company;
use App\Models\Rack;
use App\Models\RackItem;
use App\Models\Site;
use App\Support\RackLayout;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RackController extends Controller
{
    public function index(Company $company, Site $site): Response
    {
        return Inertia::render('racks/index', [
            'company' => $company->only('id', 'name'), 'site' => $site->only('id', 'name'),
            'racks' => $site->racks()->withCount(['items', 'items as devices_count' => fn ($q) => $q->whereNotNull('device_id')])->orderBy('name')->get(),
        ]);
    }

    public function show(Company $company, Site $site, Rack $rack): Response
    {
        return Inertia::render('racks/show', [
            'company' => $company->only('id', 'name'), 'site' => $site->only('id', 'name'),
            'rack' => $rack->load('items'),
            'devices' => $site->devices()->with(['model', 'manufacturer:id,name', 'rackItem.rack:id,name'])->orderBy('name')->get()
                ->map(fn ($device) => [...$device->toArray(), 'assetUrls' => $device->model?->assetUrls() ?? []]),
        ]);
    }

    public function store(Request $request, Company $company, Site $site): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $rack = DB::transaction(function () use ($data, $company, $site) {
            Company::query()->lockForUpdate()->findOrFail($company->id);

            return $company->sites()->findOrFail($site->id)->racks()->create([...$data, 'height_u' => 24, 'numbering' => 'bottom-up']);
        }, 3);

        return to_route('companies.sites.racks.show', [$company, $site, $rack]);
    }

    public function update(RackRequest $request, Company $company, Site $site, Rack $rack): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $company, $site, $rack) {
                Company::query()->lockForUpdate()->findOrFail($company->id);
                $rack = $site->racks()->lockForUpdate()->findOrFail($rack->id);
                $this->ensureRevision($rack, $request->integer('revision'));
                $existing = $rack->items()->get();
                $data = $request->validated();
                $items = isset($data['items'])
                    ? collect($data['items'])->map(fn ($item) => (new RackItem($item))->attributesToArray())->all()
                    : $existing->map->only(['key', 'kind', 'device_id', 'shelf_key', 'position', 'height', 'width', 'x'])->all();
                $missing = $existing->pluck('key')->diff(array_column($items, 'key'));
                if ($missing->diff($data['removed_keys'] ?? [])->isNotEmpty()) {
                    throw ValidationException::withMessages(['items' => 'Rimuovi esplicitamente gli elementi dalla disposizione prima di salvare.']);
                }
                $devices = $site->devices()->whereKey(array_filter(array_column($items, 'device_id')))->lockForUpdate()
                    ->with(['model' => fn ($q) => $q->sharedLock()])->get()->keyBy('id');
                if (RackItem::query()->whereIn('device_id', $devices->modelKeys())->where('rack_id', '!=', $rack->id)->exists()) {
                    throw ValidationException::withMessages(['items' => 'Un dispositivo è già montato in un altro rack. Rimuovilo prima da quel rack.']);
                }
                RackLayout::validate($items, (int) ($data['height_u'] ?? $rack->height_u), $devices);
                $rack->update([...$request->safe()->only(['name', 'height_u', 'numbering']), 'revision' => $rack->revision + 1]);
                if (isset($data['items'])) {
                    $rack->items()->delete();
                    $rack->items()->createMany($items);
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['items' => 'Un dispositivo è già montato. Ricarica il rack prima di riprovare.']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rack salvato.']);

        return back();
    }

    public function destroy(Request $request, Company $company, Site $site, Rack $rack): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted'], 'revision' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($request, $company, $site, $rack) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $rack = $site->racks()->lockForUpdate()->findOrFail($rack->id);
            $this->ensureRevision($rack, $request->integer('revision'));
            $rack->delete();
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rack eliminato.']);

        return to_route('companies.sites.racks.index', [$company, $site]);
    }

    private function ensureRevision(Rack $rack, int $revision): void
    {
        if ($rack->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Il rack è cambiato. Ricarica la pagina e verifica la disposizione prima di riprovare.']);
        }
    }
}

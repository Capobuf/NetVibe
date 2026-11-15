<?php

namespace App\Http\Controllers;

use App\Http\Requests\RackRequest;
use App\Models\Company;
use App\Models\Floor;
use App\Models\FloorItem;
use App\Models\Site;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FloorController extends Controller
{
    public function index(Company $company, Site $site): Response|RedirectResponse
    {
        $floors = $this->floors($site);
        // Open the ground floor, or the closest one to it.
        $first = $floors->sortBy(fn (Floor $floor) => abs($floor->level()) * 2 + ($floor->level() < 0 ? 1 : 0))->first();

        return $first
            ? to_route('companies.sites.floors.show', [$company, $site, $first])
            : $this->render($company, $site, $floors);
    }

    public function show(Company $company, Site $site, Floor $floor): Response
    {
        return $this->render($company, $site, $this->floors($site), $floor);
    }

    public function image(Company $company, Site $site, Floor $floor): StreamedResponse
    {
        abort_unless($floor->image_path && Storage::disk('local')->exists($floor->image_path), 404);

        return Storage::disk('local')->response($floor->image_path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-cache']);
    }

    public function store(Request $request, Company $company, Site $site): RedirectResponse
    {
        $data = $request->validate(['floor' => self::codeRules($site)], [], ['floor' => 'piano']);
        $floor = DB::transaction(function () use ($data, $company, $site) {
            Company::query()->lockForUpdate()->findOrFail($company->id);

            return $site->floors()->create(['code' => $data['floor']]);
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Piano creato.']);

        return to_route('companies.sites.floors.show', [$company, $site, $floor]);
    }

    public function update(Request $request, Company $company, Site $site, Floor $floor): RedirectResponse
    {
        $data = $request->validate([
            'floor' => self::codeRules($site, $floor),
            'revision' => ['required', 'integer', 'min:0'],
        ], [], ['floor' => 'piano']);
        DB::transaction(function () use ($data, $company, $site, $floor) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $floor = $this->lockFloor($site, $floor, $data['revision']);
            $floor->update(['code' => $data['floor'], 'revision' => $floor->revision + 1]);
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Piano aggiornato.']);

        return to_route('companies.sites.floors.show', [$company, $site, $floor]);
    }

    public function updateImage(Request $request, Company $company, Site $site, Floor $floor): RedirectResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg', 'max:20480', 'dimensions:min_width=200,min_height=200,max_width=8192,max_height=8192'],
            'positions' => ['nullable', 'in:keep,clear'],
            'revision' => ['required', 'integer', 'min:0'],
        ], [], ['image' => 'planimetria', 'positions' => 'posizioni']);
        $size = getimagesize($request->file('image')->getRealPath());
        $path = $request->file('image')->store('floors', 'local');
        if (! $size || ! $path) {
            throw new \RuntimeException('Impossibile salvare la planimetria.');
        }
        try {
            $previous = DB::transaction(function () use ($data, $company, $site, $floor, $path, $size) {
                Company::query()->lockForUpdate()->findOrFail($company->id);
                $floor = $this->lockFloor($site, $floor, $data['revision']);
                if ($floor->items()->exists()) {
                    // Positions are fractions of the image: they stay valid only for the same proportions.
                    $sameShape = abs(($size[0] / $size[1]) / ($floor->image_width / $floor->image_height) - 1) <= 0.01;
                    if (! $sameShape && ! ($data['positions'] ?? null)) {
                        throw ValidationException::withMessages(['positions' => 'La nuova planimetria ha proporzioni diverse. Scegli se mantenere o rimuovere le posizioni.']);
                    }
                    if (! $sameShape && $data['positions'] === 'clear') {
                        $floor->items()->delete();
                    }
                }
                $previous = $floor->image_path;
                $floor->forceFill(['image_path' => $path, 'image_width' => $size[0], 'image_height' => $size[1], 'revision' => $floor->revision + 1])->save();

                return $previous;
            }, 3);
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Planimetria caricata.']);

        // After the first upload the operator goes straight to placing elements.
        return to_route('companies.sites.floors.show', [$company, $site, $floor, ...($previous ? [] : ['edit' => 1])]);
    }

    public function layout(Request $request, Company $company, Site $site, Floor $floor): RedirectResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'items' => ['present', 'array', 'max:2000'],
            'items.*' => ['array:device_id,rack_id,x,y'],
            'items.*.device_id' => ['nullable', 'integer', 'min:1', 'required_without:items.*.rack_id', 'prohibits:items.*.rack_id'],
            'items.*.rack_id' => ['nullable', 'integer', 'min:1'],
            'items.*.x' => ['required', 'numeric', 'between:0,1'],
            'items.*.y' => ['required', 'numeric', 'between:0,1'],
        ], [], ['items' => 'posizioni']);
        $items = collect($data['items'])->map(fn ($item) => [
            'device_id' => $item['device_id'] ?? null, 'rack_id' => $item['rack_id'] ?? null,
            'x' => (float) $item['x'], 'y' => (float) $item['y'],
        ]);
        if ($items->pluck('device_id')->filter()->duplicates()->isNotEmpty() || $items->pluck('rack_id')->filter()->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'Ogni elemento può avere una sola posizione sul piano.']);
        }
        try {
            DB::transaction(function () use ($data, $items, $company, $site, $floor) {
                Company::query()->lockForUpdate()->findOrFail($company->id);
                $floor = $this->lockFloor($site, $floor, $data['revision']);
                if (! $floor->image_path && $items->isNotEmpty()) {
                    throw ValidationException::withMessages(['items' => 'Carica la planimetria del piano prima di posizionare gli elementi.']);
                }
                $devices = $items->pluck('device_id')->filter()->values();
                $racks = $items->pluck('rack_id')->filter()->values();
                // Positions may only reference this site's inventory.
                if ($site->devices()->whereKey($devices)->sharedLock()->count() !== $devices->count()
                    || $site->racks()->whereKey($racks)->sharedLock()->count() !== $racks->count()) {
                    throw ValidationException::withMessages(['items' => 'Alcuni elementi non esistono più nella sede. Ricarica il piano prima di salvare.']);
                }
                $floor->items()->delete();
                $floor->items()->createMany($items->all());
                $floor->update(['revision' => $floor->revision + 1]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['items' => 'Un elemento è già posizionato su questo piano. Ricarica la pagina prima di riprovare.']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Posizioni salvate.']);

        return to_route('companies.sites.floors.show', [$company, $site, $floor]);
    }

    public function destroy(Request $request, Company $company, Site $site, Floor $floor): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted'], 'revision' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($request, $company, $site, $floor) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $floor = $this->lockFloor($site, $floor, $request->integer('revision'));
            $floor->delete();
            if ($floor->image_path) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($floor->image_path));
            }
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Piano eliminato.']);

        return to_route('companies.sites.floors.index', [$company, $site]);
    }

    private function render(Company $company, Site $site, $floors, ?Floor $floor = null): Response
    {
        return Inertia::render('floors/show', [
            'company' => $company->only('id', 'name'), 'site' => $site->only('id', 'name'),
            'editing' => request()->boolean('edit'),
            'floors' => $floors->map->only('id', 'code')->values(),
            'floor' => $floor ? [
                ...$floor->only('id', 'code', 'revision'),
                'image' => $floor->image_path ? [
                    // The stored file name changes with every upload, so the URL busts cached copies.
                    'url' => route('companies.sites.floors.image', [$company, $site, $floor, 'v' => substr(md5($floor->image_path), 0, 8)]),
                    'width' => $floor->image_width, 'height' => $floor->image_height,
                ] : null,
                'items' => $floor->items()->orderBy('id')->get(['device_id', 'rack_id', 'x', 'y']),
            ] : null,
            // Positions on the site's other floors, to show where an element already is.
            'elsewhere' => FloorItem::query()->join('floors', 'floors.id', '=', 'floor_items.floor_id')
                ->where('floors.site_id', $site->id)->when($floor, fn ($query) => $query->where('floors.id', '!=', $floor->id))
                ->get(['floor_items.device_id', 'floor_items.rack_id', 'floors.code as floor']),
            'devices' => $site->devices()->with(['model:id,name', 'manufacturer:id,name', 'ipAddresses:id,device_id,address', 'rackItem.rack:id,name'])
                ->orderBy('name')->get()->map(fn ($device) => [
                    ...$device->only('id', 'name'),
                    'model' => $device->model?->name, 'manufacturer' => $device->manufacturer?->name,
                    'ips' => $device->ipAddresses->sortBy('address')->map(fn ($ip) => long2ip($ip->address))->values(),
                    'rack' => $device->rackItem?->rack?->only('id', 'name'),
                ]),
            'racks' => $site->racks()->withCount(['items as devices_count' => fn ($query) => $query->whereNotNull('device_id')])
                ->orderBy('name')->get(['id', 'name', 'floor', 'height_u']),
        ]);
    }

    private function floors(Site $site)
    {
        return $site->floors()->get()->sortBy(fn (Floor $floor) => $floor->level())->values();
    }

    private function lockFloor(Site $site, Floor $floor, int $revision): Floor
    {
        $floor = $site->floors()->lockForUpdate()->findOrFail($floor->id);
        if ($floor->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Il piano è cambiato. Ricarica la pagina e verifica le posizioni prima di riprovare.']);
        }

        return $floor;
    }

    private static function codeRules(Site $site, ?Floor $ignore = null): array
    {
        return ['required', 'string', 'max:12', 'regex:'.RackRequest::FLOOR_PATTERN,
            Rule::unique('floors', 'code')->where('site_id', $site->id)->ignore($ignore?->id)];
    }
}

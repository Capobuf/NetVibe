<?php

namespace App\Http\Controllers;

use App\Http\Requests\RackRequest;
use App\Models\Company;
use App\Models\FloorPlan;
use App\Models\FloorPlanItem;
use App\Models\Site;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FloorPlanController extends Controller
{
    public function index(Company $company, Site $site): Response|RedirectResponse
    {
        $first = $site->floorPlans()->orderBy('name')->first();

        return $first
            ? to_route('companies.sites.floor-plans.show', [$company, $site, $first])
            : $this->render($company, $site);
    }

    public function show(Company $company, Site $site, FloorPlan $floorPlan): Response
    {
        return $this->render($company, $site, $floorPlan);
    }

    public function image(Company $company, Site $site, FloorPlan $floorPlan): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($floorPlan->image_path), 404);

        return Storage::disk('local')->response($floorPlan->image_path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-cache']);
    }

    public function store(Request $request, Company $company, Site $site): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('floor_plans')->where('site_id', $site->id)],
            'floor' => RackRequest::floorRules(),
            'image' => self::imageRules(true),
        ], [], self::attributes());
        $plan = $this->withImage($request->file('image'), fn (string $path, array $size) => DB::transaction(function () use ($data, $company, $site, $path, $size) {
            Company::query()->lockForUpdate()->findOrFail($company->id);

            return $site->floorPlans()->forceCreate([
                'name' => $data['name'], 'floor' => $data['floor'] ?? null, 'image_path' => $path,
                'image_width' => $size[0], 'image_height' => $size[1],
            ]);
        }, 3));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Planimetria creata.']);

        return to_route('companies.sites.floor-plans.show', [$company, $site, $plan, 'edit' => 1]);
    }

    public function update(Request $request, Company $company, Site $site, FloorPlan $floorPlan): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('floor_plans')->where('site_id', $site->id)->ignore($floorPlan->id)],
            'floor' => RackRequest::floorRules(),
            'image' => self::imageRules(false),
            'positions' => ['nullable', 'in:keep,clear'],
            'revision' => ['required', 'integer', 'min:0'],
        ], [], self::attributes());
        $previous = $floorPlan->image_path;
        $save = function (?string $path, ?array $size) use ($data, $company, $site, $floorPlan) {
            DB::transaction(function () use ($data, $company, $site, $floorPlan, $path, $size) {
                Company::query()->lockForUpdate()->findOrFail($company->id);
                $plan = $this->lockPlan($site, $floorPlan, $data['revision']);
                $attributes = ['name' => $data['name'], 'floor' => $data['floor'] ?? null, 'revision' => $plan->revision + 1];
                if ($path) {
                    // Positions are fractions of the image: they stay valid only for the same proportions.
                    $sameShape = abs(($size[0] / $size[1]) / ($plan->image_width / $plan->image_height) - 1) <= 0.01;
                    $positions = $data['positions'] ?? null;
                    if (! $sameShape && ! $positions && $plan->items()->exists()) {
                        throw ValidationException::withMessages(['positions' => 'La nuova immagine ha proporzioni diverse. Scegli se mantenere o rimuovere le posizioni.']);
                    }
                    if ($positions === 'clear') {
                        $plan->items()->delete();
                    }
                    $plan->forceFill(['image_path' => $path, 'image_width' => $size[0], 'image_height' => $size[1]]);
                }
                $plan->update($attributes);
            }, 3);
        };
        $request->hasFile('image') ? $this->withImage($request->file('image'), $save) : $save(null, null);
        if ($request->hasFile('image')) {
            Storage::disk('local')->delete($previous);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Planimetria aggiornata.']);

        return to_route('companies.sites.floor-plans.show', [$company, $site, $floorPlan]);
    }

    public function layout(Request $request, Company $company, Site $site, FloorPlan $floorPlan): RedirectResponse
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
            throw ValidationException::withMessages(['items' => 'Ogni elemento può avere una sola posizione sulla planimetria.']);
        }
        try {
            DB::transaction(function () use ($data, $items, $company, $site, $floorPlan) {
                Company::query()->lockForUpdate()->findOrFail($company->id);
                $plan = $this->lockPlan($site, $floorPlan, $data['revision']);
                $devices = $items->pluck('device_id')->filter()->values();
                $racks = $items->pluck('rack_id')->filter()->values();
                // Positions may only reference this site's inventory.
                if ($site->devices()->whereKey($devices)->sharedLock()->count() !== $devices->count()
                    || $site->racks()->whereKey($racks)->sharedLock()->count() !== $racks->count()) {
                    throw ValidationException::withMessages(['items' => 'Alcuni elementi non esistono più nella sede. Ricarica la planimetria prima di salvare.']);
                }
                $plan->items()->delete();
                $plan->items()->createMany($items->all());
                $plan->update(['revision' => $plan->revision + 1]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['items' => 'Un elemento è già posizionato su questa planimetria. Ricarica la pagina prima di riprovare.']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Planimetria salvata.']);

        return to_route('companies.sites.floor-plans.show', [$company, $site, $floorPlan]);
    }

    public function destroy(Request $request, Company $company, Site $site, FloorPlan $floorPlan): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted'], 'revision' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($request, $company, $site, $floorPlan) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $plan = $this->lockPlan($site, $floorPlan, $request->integer('revision'));
            $plan->delete();
            DB::afterCommit(fn () => Storage::disk('local')->delete($plan->image_path));
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Planimetria eliminata.']);

        return to_route('companies.sites.floor-plans.index', [$company, $site]);
    }

    private function render(Company $company, Site $site, ?FloorPlan $plan = null): Response
    {
        $route = [$company, $site];

        return Inertia::render('floor-plans/show', [
            'company' => $company->only('id', 'name'), 'site' => $site->only('id', 'name'),
            'editing' => request()->boolean('edit'),
            'plans' => $site->floorPlans()->orderBy('name')->get(['id', 'name', 'floor']),
            'plan' => $plan ? [
                ...$plan->only('id', 'name', 'floor', 'image_width', 'image_height', 'revision'),
                // The file name changes with every replacement, so the URL busts cached copies.
                'image_url' => route('companies.sites.floor-plans.image', [...$route, $plan, 'v' => substr(md5($plan->image_path), 0, 8)]),
                'items' => $plan->items()->orderBy('id')->get(['device_id', 'rack_id', 'x', 'y']),
            ] : null,
            // Placements on the other floor plans of the site, to show where an element already is.
            'elsewhere' => FloorPlanItem::query()->join('floor_plans', 'floor_plans.id', '=', 'floor_plan_items.floor_plan_id')
                ->where('floor_plans.site_id', $site->id)->when($plan, fn ($query) => $query->where('floor_plans.id', '!=', $plan->id))
                ->get(['floor_plan_items.device_id', 'floor_plan_items.rack_id', 'floor_plans.name as plan']),
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

    private function lockPlan(Site $site, FloorPlan $plan, int $revision): FloorPlan
    {
        $plan = $site->floorPlans()->lockForUpdate()->findOrFail($plan->id);
        if ($plan->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'La planimetria è cambiata. Ricarica la pagina e verifica le posizioni prima di riprovare.']);
        }

        return $plan;
    }

    /** Stores the image, runs the callback and removes the file again if saving fails. */
    private function withImage(UploadedFile $file, callable $save): mixed
    {
        $size = getimagesize($file->getRealPath());
        $path = $file->store('floor-plans', 'local');
        if (! $size || ! $path) {
            throw new \RuntimeException('Impossibile salvare l’immagine.');
        }
        try {
            return $save($path, [$size[0], $size[1]]);
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    private static function imageRules(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'file', 'image', 'mimes:png,jpg,jpeg', 'max:20480', 'dimensions:min_width=200,min_height=200,max_width=8192,max_height=8192'];
    }

    private static function attributes(): array
    {
        return ['name' => 'nome', 'floor' => 'piano', 'image' => 'immagine', 'positions' => 'posizioni'];
    }
}

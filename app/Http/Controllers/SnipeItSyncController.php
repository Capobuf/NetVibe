<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\SnipeItAsset;
use App\Models\SnipeItSync;
use App\Support\SnipeItSync as Synchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SnipeItSyncController extends Controller
{
    public function __construct(private Synchronizer $synchronizer) {}

    public function refresh(Company $company): RedirectResponse
    {
        $this->synchronizer->refresh($company);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sincronizzazione completata.']);

        return back();
    }

    public function index(Request $request, Company $company): Response
    {
        $filters = $request->validate([
            'view' => ['sometimes', Rule::in(SnipeItAsset::VIEWS)],
            'q' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(['local', 'name', 'model', 'serial', 'ip', 'state'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([25, 50, 100])],
            'group' => ['sometimes', Rule::in(['category', 'none'])],
        ]);
        $view = $filters['view'] ?? 'devices';
        $sync = SnipeItSync::current($company);
        $query = SnipeItAsset::where('sync_id', $sync?->id ?? 0)
            ->forView($view)
            ->with('ipAddress.subnet', 'device.site', 'device.model.manufacturer', 'device.model.modelType', 'externalModel.model.manufacturer', 'externalModel.model.modelType')
            ->leftJoin('devices as local', 'local.id', '=', 'snipe_it_assets.device_id')
            ->leftJoin('snipe_it_models as source_model', 'source_model.id', '=', 'snipe_it_assets.snipe_it_model_id')
            ->select('snipe_it_assets.*');
        $table = 'snipe_it_assets';
        if ($search = $filters['q'] ?? null) {
            $query->where(function ($q) use ($search, $table) {
                $q->whereLike('local.name', '%'.$search.'%');
                foreach (['name', 'asset_tag', 'serial', 'network->ip', 'network->mac'] as $field) {
                    $q->orWhereLike($table.'.snapshot->'.$field, '%'.$search.'%');
                }
                $q->orWhereLike('source_model.snapshot->name', '%'.$search.'%')
                    ->orWhereLike('source_model.snapshot->manufacturer', '%'.$search.'%')
                    ->orWhereLike('source_model.snapshot->category', '%'.$search.'%');
            });
        }
        $columns = [
            'local' => 'local.name', 'name' => $table.'.snapshot->name',
            'model' => 'source_model.snapshot->name',
            'serial' => $table.'.snapshot->serial',
            'state' => $table.'.state',
        ];
        if (($filters['group'] ?? 'category') === 'category') {
            $query->orderByRaw("JSON_VALUE(source_model.snapshot, '$.category') IS NULL")
                ->orderBy('source_model.snapshot->category');
        }
        if (($filters['sort'] ?? null) && ($filters['direction'] ?? null)) {
            if ($filters['sort'] === 'name') {
                $query->orderByRaw("COALESCE(JSON_VALUE(snipe_it_assets.snapshot, '$.name'), JSON_VALUE(snipe_it_assets.snapshot, '$.serial')) ".$filters['direction']);
            } elseif ($filters['sort'] === 'ip') {
                $query->orderByRaw("INET_ATON(JSON_VALUE(snipe_it_assets.snapshot, '$.network.ip')) ".$filters['direction']);
            } else {
                $query->orderBy($columns[$filters['sort']], $filters['direction']);
            }
        }
        $rows = $query->orderBy($table.'.id')->paginate($filters['per_page'] ?? 25)->onEachSide(1)->withQueryString();
        $rows->through(function ($row) use ($sync) {
            $row->setRelation('sync', $sync);
            $candidates = $row->device_id || $row->state === 'excluded' ? collect() : $this->synchronizer->deviceCandidates($row)->whereDoesntHave('snipeItAsset')->with('site', 'model.manufacturer', 'model.modelType')->get();
            $external = $row->externalModel;
            $modelCandidates = $external && ! $external->device_model_id
                ? $this->synchronizer->modelCandidates($external)->with('manufacturer', 'modelType')->get() : collect();
            $model = $external?->model ?? ($modelCandidates->count() === 1 ? $modelCandidates->first() : null);
            $problem = $row->problem ?? ($row->device_id ? $this->synchronizer->modelProblem($external) : null);
            if (! $problem && ! $row->device_id && ! $row->ip_address_id && $row->state !== 'excluded') {
                $problem = match (true) {
                    $row->state === 'detached' => 'Conferma una nuova associazione dopo lo scollegamento o la rimozione.',
                    $row->state === 'site' => 'Scegli la sede del dispositivo.',
                    $candidates->isNotEmpty() => 'Possibile dispositivo esistente: verifica la corrispondenza.',
                    default => null,
                };
            }

            return [
                'id' => $row->id, 'snapshot' => $row->snapshot, 'problem' => $problem,
                'state' => $row->device_id ? 'linked' : ($row->state === 'excluded' ? 'excluded' : ($problem ? 'review' : ($row->ip_address_id ? 'ipam' : ($candidates->isNotEmpty() ? 'candidate' : 'new')))),
                'local' => $row->device ? $this->deviceOption($row->device) : null,
                'candidates' => $candidates->map(fn ($device) => $this->deviceOption($device))->all(),
                'model' => $external?->snapshot, 'present' => $row->present,
                'model_local' => $model ? $this->modelOption($model) : null,
                'model_linked' => (bool) $external?->device_model_id,
                'model_ambiguous' => $modelCandidates->count() > 1,
                'model_problem' => $row->device_id ? $external?->problem : null,
                'ipam_url' => $row->ipAddress ? route('companies.sites.subnets.show', [$sync->company_id, $row->ipAddress->subnet->site_id, $row->ipAddress->subnet_id, 'ip_address' => $row->ip_address_id]) : null,
                'site_id' => $row->device?->site_id,
            ];
        });

        return Inertia::render('integrations/snipe-it-records', [
            'company' => $company->only('id', 'name'), 'sync' => $sync?->only('id', 'revision', 'activated_at', 'synced_at', 'error'),
            'active' => $sync?->isActive() ?? false, 'view' => $view, 'filters' => $filters,
            'rows' => $rows, 'sites' => $company->sites()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function options(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:models,devices'], 'q' => ['nullable', 'string', 'max:255']]);
        $search = '%'.($data['q'] ?? '').'%';
        if ($data['kind'] === 'models') {
            $items = DeviceModel::with('manufacturer', 'modelType')->where(fn ($q) => $q->whereLike('name', $search)
                ->orWhereHas('manufacturer', fn ($q) => $q->whereLike('name', $search)))
                ->orderBy('name')->limit(30)->get()->map(fn ($model) => $this->modelOption($model));
        } else {
            $items = Device::with('site', 'model.manufacturer', 'model.modelType')->whereHas('site', fn ($q) => $q->where('company_id', $company->id))
                ->whereDoesntHave('snipeItAsset')->where(fn ($q) => $q->whereLike('name', $search)->orWhereLike('serial_number', $search))
                ->orderBy('name')->limit(30)->get()->map(fn ($device) => $this->deviceOption($device));
        }

        return response()->json($items);
    }

    public function confirm(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'sync_id' => ['required', 'integer'], 'revision' => ['required', 'integer'],
            'view' => ['required', Rule::in(SnipeItAsset::VIEWS)], 'activate' => ['required', 'boolean'],
            'decisions' => ['present', 'array', 'max:100'], 'decisions.*.id' => ['required', 'integer', 'distinct'],
            'decisions.*.action' => ['required', 'in:create,link,exclude,restore'],
            'decisions.*.model_id' => ['nullable', 'integer', Rule::exists('device_models', 'id')],
            'decisions.*.change_model' => ['sometimes', 'boolean'],
            'decisions.*.target_id' => ['nullable', 'integer'], 'decisions.*.site_id' => ['nullable', 'integer'],
        ]);
        $this->synchronizer->confirm($company, $data['sync_id'], $data['revision'], $data['decisions'], $data['activate']);
        $sync = SnipeItSync::findOrFail($data['sync_id']);
        $pending = $sync->assets()
            ->whereIn('id', array_column($data['decisions'], 'id'))->whereNotNull('problem')->count();
        Inertia::flash('toast', ['type' => $pending ? 'error' : 'success', 'message' => $pending
            ? "Scelte elaborate: {$pending} dispositivi restano da verificare."
            : ($data['activate'] && ! $sync->activated_at ? 'Scelte confermate. Per attivare la sincronizzazione collega almeno un dispositivo.' : 'Scelte confermate.')]);

        return back();
    }

    public function detach(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['asset_id' => ['required', 'integer'], 'confirmed' => ['accepted'], 'exclude' => ['required', 'boolean']]);
        $this->synchronizer->detach($company, $data['asset_id'], $data['exclude']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispositivo scollegato da Snipe-IT.']);

        return back();
    }

    private function modelOption(DeviceModel $model): array
    {
        return ['id' => $model->id, 'name' => $model->manufacturer->name.' · '.$model->name,
            'detail' => $model->modelType?->name, 'url' => route('manufacturers.models.edit', [$model->manufacturer_id, $model->id])];
    }

    private function deviceOption(Device $device): array
    {
        return ['id' => $device->id, 'name' => $device->name, 'detail' => implode(' · ', array_filter([$device->site->name, $device->model?->name, $device->serial_number])),
            'model' => $device->model ? $this->modelOption($device->model) : null,
            'url' => route('companies.sites.devices.show', [$device->site->company_id, $device->site_id, $device->id])];
    }
}

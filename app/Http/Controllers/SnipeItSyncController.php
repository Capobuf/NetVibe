<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\SnipeItAsset;
use App\Models\SnipeItCompanyLink;
use App\Models\SnipeItSetting;
use App\Models\SnipeItSync;
use App\Support\SnipeItSync as Synchronizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SnipeItSyncController extends Controller
{
    public const NO_CATEGORY = '__none';

    public function __construct(private Synchronizer $synchronizer) {}

    public function refresh(Company $company): RedirectResponse
    {
        // Assets created by this run get ids above the current maximum.
        $lastId = (int) SnipeItAsset::max('id');
        $new = $this->synchronizer->refresh($company)->assets()->forView('pending')
            ->where('snipe_it_assets.id', '>', $lastId)->count();
        Inertia::flash('toast', $new ? [
            'type' => 'success',
            'message' => $new === 1 ? 'Sincronizzazione completata · 1 nuovo asset da confermare.'
                : "Sincronizzazione completata · {$new} nuovi asset da confermare.",
            'action' => ['label' => 'Conferma', 'url' => route('companies.settings.integrations.snipe-it.records', [$company, 'view' => 'pending'])],
        ] : ['type' => 'success', 'message' => 'Sincronizzazione completata.']);

        return back();
    }

    public function index(Request $request, Company $company): Response
    {
        $filters = $request->validate([
            'view' => ['sometimes', Rule::in(SnipeItAsset::VIEWS)],
            ...$this->filterRules(),
            'sort' => ['nullable', Rule::in(['local', 'name', 'category', 'model', 'serial', 'ip'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
        ]);
        $view = $filters['view'] ?? 'pending';
        $sync = SnipeItSync::current($company);
        $query = $this->filtered($sync, $view, $filters)
            ->with('ipAddress.subnet', 'device.site', 'device.model.manufacturer', 'device.model.modelType', 'externalModel.model.manufacturer', 'externalModel.model.modelType')
            ->leftJoin('devices as local', 'local.id', '=', 'snipe_it_assets.device_id')
            ->select('snipe_it_assets.*');
        $table = 'snipe_it_assets';
        $columns = [
            'local' => 'local.name', 'serial' => $table.'.snapshot->serial',
            'model' => 'source_model.snapshot->name', 'category' => 'source_model.snapshot->category',
        ];
        if (($filters['sort'] ?? null) && ($filters['direction'] ?? null)) {
            if ($filters['sort'] === 'name') {
                $query->orderByRaw("COALESCE(JSON_VALUE(snipe_it_assets.snapshot, '$.name'), JSON_VALUE(snipe_it_assets.snapshot, '$.serial')) ".$filters['direction']);
            } elseif ($filters['sort'] === 'ip') {
                $query->orderByRaw("INET_ATON(JSON_VALUE(snipe_it_assets.snapshot, '$.network.ip')) ".$filters['direction']);
            } else {
                $query->orderBy($columns[$filters['sort']], $filters['direction']);
            }
        } else {
            // Default order keeps assets of the same type together.
            $query->orderByRaw("JSON_VALUE(source_model.snapshot, '$.category') IS NULL")
                ->orderBy('source_model.snapshot->category')
                ->orderByRaw("JSON_VALUE(snipe_it_assets.snapshot, '$.name')");
        }
        $rows = $query->orderBy($table.'.id')->paginate($filters['per_page'] ?? 25)->onEachSide(1)->withQueryString();
        $deviceQuery = $modelQuery = null;
        foreach ($rows as $row) {
            $row->setRelation('sync', $sync);
            if (! $row->device_id && $row->state !== 'excluded') {
                // Keep SQL matching rules and collation while batching only the current page.
                $candidates = $this->synchronizer->deviceCandidates($row)->whereDoesntHave('snipeItAsset')
                    ->select('devices.*')->selectRaw('? as candidate_asset_id', [$row->id]);
                $deviceQuery = $deviceQuery ? $deviceQuery->unionAll($candidates) : $candidates;
            }
        }
        foreach ($rows->getCollection()->pluck('externalModel')->filter()->unique('id') as $external) {
            if (! $external->device_model_id) {
                $candidates = $this->synchronizer->modelCandidates($external)
                    ->select('device_models.*')->selectRaw('? as candidate_external_id', [$external->id]);
                $modelQuery = $modelQuery ? $modelQuery->unionAll($candidates) : $candidates;
            }
        }
        $devicesByAsset = $deviceQuery?->with('site', 'model.manufacturer', 'model.modelType')->get()->groupBy('candidate_asset_id') ?? collect();
        $modelsByExternal = $modelQuery?->with('manufacturer', 'modelType')->get()->groupBy('candidate_external_id') ?? collect();
        $server = $sync?->connection?->server_url;
        $rows->through(function ($row) use ($sync, $devicesByAsset, $modelsByExternal, $server) {
            $candidates = $devicesByAsset->get($row->id, collect());
            $external = $row->externalModel;
            $modelCandidates = $modelsByExternal->get($external?->id, collect());
            $model = $external?->model ?? ($modelCandidates->count() === 1 ? $modelCandidates->first() : null);
            $problem = $row->problem ?? ($row->device_id ? $this->synchronizer->modelProblem($external) : null);

            return [
                'id' => $row->id, 'snapshot' => $row->snapshot, 'problem' => $problem,
                'state' => $row->device_id ? 'linked' : ($row->state === 'excluded' ? 'excluded' : ($row->ip_address_id || $row->removed_address !== null ? 'ipam' : 'pending')),
                'detached' => $row->state === 'detached',
                'url' => $server ? $server.'/hardware/'.$row->external_id : null,
                'local' => $row->device ? $this->deviceOption($row->device) : null,
                'candidates' => $candidates->map(fn ($device) => $this->deviceOption($device))->all(),
                'model' => $external?->snapshot, 'present' => $row->present,
                'model_local' => $model ? $this->modelOption($model) : null,
                'model_linked' => (bool) $external?->device_model_id,
                'model_ambiguous' => $modelCandidates->count() > 1,
                'model_problem' => $row->device_id ? $external?->problem : null,
                'ipam_url' => $row->ipAddress ? route('companies.sites.subnets.show', [$sync->company_id, $row->ipAddress->subnet->site_id, $row->ipAddress->subnet_id, 'ip_address' => $row->ip_address_id]) : null,
            ];
        });
        $categoryFilters = collect($filters)->except('category')->all();

        return Inertia::render('integrations/snipe-it-records', [
            'company' => $company->only('id', 'name'), 'sync' => $sync?->only('id', 'revision', 'activated_at', 'synced_at', 'error'),
            'integration' => $this->integration($company),
            'active' => $sync?->isActive() ?? false, 'view' => $view, 'filters' => $filters,
            'counts' => $sync?->counts() ?? [...array_fill_keys([...SnipeItAsset::VIEWS, 'problems'], 0), 'problems_by_view' => []],
            'categories' => $this->filtered($sync, $view, $categoryFilters)
                ->selectRaw("JSON_VALUE(source_model.snapshot, '$.category') as name, count(*) as total")
                ->groupByRaw("JSON_VALUE(source_model.snapshot, '$.category')")->orderByRaw('name IS NULL')->orderBy('name')
                ->get()->map(fn ($item) => ['name' => $item->name, 'total' => (int) $item->total]),
            'rows' => $rows, 'sites' => $company->sites()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Applies exclude/restore to every asset matching the current filters, not only the visible page. */
    public function bulk(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'sync_id' => ['required', 'integer'], 'revision' => ['required', 'integer'],
            'view' => ['required', Rule::in(SnipeItAsset::VIEWS)], 'action' => ['required', 'in:exclude,restore'],
            ...$this->filterRules(),
        ]);
        if (($data['action'] === 'restore') !== ($data['view'] === 'excluded') || $data['view'] === 'linked') {
            throw ValidationException::withMessages(['sync' => 'Azione non disponibile in questa vista.']);
        }
        $sync = SnipeItSync::where('company_id', $company->id)->findOrFail($data['sync_id']);
        $ids = $this->filtered($sync, $data['view'], $data)->whereNull('snipe_it_assets.device_id')
            ->pluck('snipe_it_assets.id');
        $this->synchronizer->confirm($company, $sync->id, $data['revision'],
            $ids->map(fn ($id) => ['id' => $id, 'action' => $data['action']])->all(), false);
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['action'] === 'exclude'
            ? "{$ids->count()} asset esclusi." : "{$ids->count()} asset riammessi."]);

        return back();
    }

    /** Connection and association state shown in the Snipe-IT header. */
    private function integration(Company $company): array
    {
        $setting = SnipeItSetting::forCompany($company);
        $global = SnipeItSetting::forCompany(null);
        $connection = $setting->use_global ? $global->connection : $setting->connection;
        $link = SnipeItCompanyLink::where('company_id', $company->id)->latest('id')->first();

        return [
            'connected' => $connection !== null,
            'interval' => $global->sync_interval,
            'association' => $link ? [
                'name' => $link->external_company_name,
                'confirmed' => $connection && $link->connection_id === $connection->id && $link->confirmed_at !== null,
            ] : null,
        ];
    }

    private function filterRules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'problem' => ['nullable', 'boolean'],
            'category' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function filtered(?SnipeItSync $sync, string $view, array $filters): Builder
    {
        $table = 'snipe_it_assets';
        $query = SnipeItAsset::where('sync_id', $sync?->id ?? 0)->forView($view)
            ->leftJoin('snipe_it_models as source_model', 'source_model.id', '=', 'snipe_it_assets.snipe_it_model_id');
        if ($search = $filters['q'] ?? null) {
            $query->where(function ($q) use ($search, $table) {
                $q->whereHas('device', fn ($q) => $q->whereLike('name', '%'.$search.'%'));
                foreach (['name', 'asset_tag', 'serial', 'network->ip', 'network->mac'] as $field) {
                    $q->orWhereLike($table.'.snapshot->'.$field, '%'.$search.'%');
                }
                $q->orWhereLike('source_model.snapshot->name', '%'.$search.'%')
                    ->orWhereLike('source_model.snapshot->manufacturer', '%'.$search.'%')
                    ->orWhereLike('source_model.snapshot->category', '%'.$search.'%');
            });
        }
        if ($filters['problem'] ?? false) {
            $query->withProblem();
        }
        if (($filters['category'] ?? null) !== null) {
            // Empty query strings become null, so a sentinel selects assets without a Snipe-IT category.
            $filters['category'] === self::NO_CATEGORY ? $query->whereRaw("JSON_VALUE(source_model.snapshot, '$.category') IS NULL")
                : $query->whereRaw("JSON_VALUE(source_model.snapshot, '$.category') = ?", [$filters['category']]);
        }

        return $query;
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
            'activate' => ['required', 'boolean'],
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

    public function restoreIp(Company $company, int $asset): RedirectResponse
    {
        $restored = $this->synchronizer->restoreIp($company, $asset);
        Inertia::flash('toast', $restored->ip_address_id
            ? ['type' => 'success', 'message' => 'Documentazione ripristinata.']
            : ['type' => 'warning', 'message' => 'Rimozione annullata, IP non documentato. '.($restored->problem ?? 'Verifica l’asset in Snipe-IT.')]);

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

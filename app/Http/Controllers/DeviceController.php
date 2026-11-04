<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmDeletionRequest;
use App\Http\Requests\DeviceRequest;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\IpAddress;
use App\Models\IpStatus;
use App\Models\Manufacturer;
use App\Models\ModelType;
use App\Models\Site;
use App\Models\SnipeItSync;
use App\Support\DeletionImpact;
use App\Support\NetworkExclusions;
use App\Support\UnifiInventory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DeviceController extends Controller
{
    /** @return Collection<int, Manufacturer> */
    private function catalog(): Collection
    {
        return Manufacturer::query()->with(['models:id,manufacturer_id,name,model_type_id', 'models.modelType:id,is_infrastructure'])->orderBy('name')->get(['id', 'name'])
            ->each(fn ($manufacturer) => $manufacturer->models->each(fn ($model) => $model
                ->setAttribute('is_infrastructure', $model->isInfrastructure())->unsetRelation('modelType')));
    }

    public function index(Request $request, Company $company, Site $site): Response
    {
        $options = $request->validate(['view' => ['sometimes', 'in:infrastructure,all']]);

        return Inertia::render('devices/index', [
            'view' => $options['view'] ?? 'infrastructure',
            'company' => $company->only('id', 'name'), 'site' => $site->only('id', 'name'),
            'manufacturers' => $this->catalog(),
            'snipePending' => $request->user()->is_superadmin
                ? (SnipeItSync::current($company)?->assets()->where('state', 'site')->where('present', true)->count() ?? 0) : 0,
            'types' => fn () => ModelType::query()->orderBy('name')->get(['id', 'name']),
            'devices' => $site->devices()
                ->select(['id', 'site_id', 'name', 'manufacturer_id', 'device_model_id', 'infrastructure_override', 'image_path'])
                ->with([
                    'manufacturer:id,name', 'model:id,name,model_type_id', 'model.modelType:id,name,is_infrastructure',
                    'snipeItAsset:id,device_id,snapshot', 'ipAddresses:id,device_id,address',
                    'unifiDevice:id,device_id,unifi_site_id,observed,present,synced_at',
                    'unifiDevice.unifiSite:id,site_id,unifi_connection_id,available,sync_status',
                    'unifiDevice.unifiSite.unifiConnection:id,enabled',
                ])->withExists('mikrotikConnection')->orderBy('name')->get()
                ->map(fn ($device) => [...$device->makeHidden(['unifiDevice', 'snipeItAsset', 'ipAddresses'])->toArray(), 'ips' => $device->ipAddresses->map(fn ($ip) => long2ip($ip->address)),
                    'image_url' => $device->imageUrl($company->id),
                    'is_infrastructure' => $device->isInfrastructure(),
                    'unifi' => $device->unifiDevice && $device->unifiDevice->unifiSite->site_id === $site->id ? [
                        'state' => $device->unifiDevice->observed['state'] ?? null,
                        'stale' => $device->unifiDevice->stale(), 'synced_at' => $device->unifiDevice->synced_at,
                    ] : null,
                ]),
        ]);
    }

    public function show(Request $request, Company $company, Site $site, Device $device): Response
    {
        $options = $request->validate([
            'view' => ['sometimes', 'in:infrastructure,all'],
            'interface' => ['sometimes', 'integer', 'min:1'],
            'tab' => ['sometimes', 'in:interfaces,ips,mikrotik'],
            'manage' => ['sometimes', 'in:general,mikrotik,snipe-it'],
        ]);
        $selectedInterface = isset($options['interface']) ? $device->interfaces()->findOrFail($options['interface']) : null;
        $device->load(['manufacturer', 'model.modelType', 'interfaces', 'ipAddresses.subnet.vlan']);
        $asset = $device->snipeItAsset()->with('sync.connection', 'externalModel.model')->first();
        $device->setRelation('snipeItAsset', $asset)->makeHidden('snipeItAsset');
        $device->setAttribute('is_infrastructure', $device->isInfrastructure());
        $device->setAttribute('image_url', $device->imageUrl($company->id));
        $unifiSource = $device->unifiDevice()->with('unifiSite.unifiConnection')->first();
        if ($unifiSource) {
            $unifiSource->setRelation('device', $device);
        }
        $unifi = $unifiSource ? app(UnifiInventory::class)->deviceDetails($unifiSource) : null;
        if ($unifi && ! $request->user()->is_superadmin) {
            $unifi['revision'] = null;
        }
        $device->setAttribute('unifi', $unifi);

        return Inertia::render('devices/show', [
            'view' => $options['view'] ?? 'infrastructure',
            'company' => $company->only('id', 'name'), 'site' => $site->only('id', 'name'),
            'device' => $device,
            'mikrotikInterfaces' => $device->mikrotikSources()->where('kind', 'interface')
                ->where('generation', $device->mikrotikConnection()->value('source_generation'))
                ->where('state', 'current')->whereNull('problem')->with('device.mikrotikConnection')->get()
                ->map(fn ($source) => $source->interfaceDetails()),
            'initialTab' => ($options['tab'] ?? null) === 'ips' ? 'ips' : 'interfaces',
            'initialManagementTab' => $options['manage'] ?? (($options['tab'] ?? null) === 'mikrotik' ? 'mikrotik' : null),
            'mikrotikSources' => $request->user()->is_superadmin
                ? Inertia::optional(fn () => (new NetworkExclusions($site->id))->display($device->mikrotikSources()->with('device.mikrotikConnection')->orderByDesc('seen_at')->get())) : [],
            'isMikrotik' => $device->manufacturer->isMikrotik(),
            'mikrotik' => $request->user()->is_superadmin && $device->manufacturer->isMikrotik()
                ? $device->mikrotikConnection()->first()?->summary() : null,
            'snipeIt' => $asset ? [
                ...$asset->only('id', 'present', 'problem', 'snapshot'),
                'url' => $asset->sync->connection->server_url.'/hardware/'.$asset->external_id,
                'active' => $asset->sync->isActive(), 'synced_at' => $asset->sync->synced_at,
                'error' => $asset->sync->error,
                'model' => $asset->externalModel?->snapshot,
                'model_problem' => $asset->externalModel?->problem,
            ] : null,
            'initialInterfaceId' => $selectedInterface?->id,
            'assetUrls' => $device->model?->assetUrls() ?? [],
            'manufacturers' => $this->catalog(),
            'types' => fn () => ModelType::query()->orderBy('name')->get(['id', 'name']),
            'ips' => $device->ipAddresses->map(fn ($ip) => [...$ip->toArray(), 'ip' => long2ip($ip->address)]),
            'availableIps' => Inertia::optional(fn () => IpAddress::query()->whereIn('subnet_id', $site->subnets()->visible()->select('id'))
                ->where(fn ($query) => $query->whereNull('device_id')->orWhere(fn ($query) => $query->where('device_id', $device->id)->whereNull('device_interface_id')))
                ->with('subnet:id,cidr')->orderBy('address')->get()
                ->map(fn ($ip) => [...$ip->only('id', 'hostname', 'description', 'mac_address', 'subnet'), 'ip' => long2ip($ip->address)])),
            'subnets' => $site->subnets()->visible()->with('excludedRanges')->orderBy('network_address')->get(['id', 'cidr', 'name'])
                ->map(fn ($subnet) => [...$subnet->only('id', 'cidr', 'name'),
                    'excludedRanges' => $subnet->excludedRanges->map(fn ($range) => [
                        'id' => $range->id, 'start_ip' => long2ip($range->start_address), 'end_ip' => long2ip($range->end_address),
                    ]),
                ]),
            'statuses' => IpStatus::query()->orderBy('name')->get(['id', 'name']),
            'tags' => $company->tags()->orderBy('name')->get(['id', 'name', 'color']),
        ]);
    }

    public function image(Company $company, Site $site, Device $device): StreamedResponse
    {
        abort_unless($device->image_path && Storage::disk('local')->exists($device->image_path), 404);

        return Storage::disk('local')->response($device->image_path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-cache']);
    }

    public function integrations(Request $request, Company $company, Site $site, Device $device): RedirectResponse
    {
        $options = $request->validate(['tab' => ['sometimes', 'in:mikrotik,snipe-it']]);

        return to_route('companies.sites.devices.show', [$company, $site, $device, 'manage' => $options['tab'] ?? 'general']);
    }

    public function store(DeviceRequest $request, Company $company, Site $site): RedirectResponse
    {
        return $this->save($request, $company, $site);
    }

    public function update(DeviceRequest $request, Company $company, Site $site, Device $device): RedirectResponse
    {
        return $this->save($request, $company, $site, $device);
    }

    public function classification(Request $request, Company $company, Site $site, Device $device): RedirectResponse
    {
        $data = $request->validate([
            'infrastructure_override' => ['present', 'nullable', 'boolean'],
        ], [], ['infrastructure_override' => 'classificazione']);
        $device->update($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Classificazione salvata.']);

        return back();
    }

    private function save(DeviceRequest $request, Company $company, Site $site, ?Device $device = null): RedirectResponse
    {
        $data = $request->safe()->except(['image', 'remove_image']);
        $uploaded = $request->file('image');
        $stored = $uploaded?->store('devices', 'local');
        if ($uploaded && ! $stored) {
            throw new \RuntimeException('Impossibile salvare l’immagine.');
        }
        $previous = null;
        try {
            $record = DB::transaction(function () use ($data, $company, $site, $device, $request, $stored, &$previous) {
                $model = DeviceModel::query()->sharedLock()->whereKey($data['device_model_id'])->firstOrFail();
                Manufacturer::query()->sharedLock()->findOrFail($model->manufacturer_id);
                $data['manufacturer_id'] = $model->manufacturer_id;
                Company::query()->lockForUpdate()->findOrFail($company->id);
                $site = Site::query()->findOrFail($site->id);
                $record = $device ? $site->devices()->lockForUpdate()->findOrFail($device->id) : $site->devices()->make();
                if ($record->exists && $record->snipeItAsset()->exists()) {
                    if ($stored || $request->boolean('remove_image')) {
                        throw ValidationException::withMessages(['image' => 'L’immagine è gestita da Snipe-IT. Modificala alla fonte oppure scollega il dispositivo.']);
                    }
                    $changed = (clone $record)->fill($data)->getDirty();
                    foreach (['name', 'serial_number', 'asset_tag', 'device_model_id', 'manufacturer_id'] as $field) {
                        if (array_key_exists($field, $changed)) {
                            throw ValidationException::withMessages([$field => 'Questo campo è gestito da Snipe-IT. Modificalo alla fonte oppure scollega il dispositivo.']);
                        }
                    }
                }
                if ($record->exists && ($problem = $record->modelChangeProblem($model->id))) {
                    throw ValidationException::withMessages(['device_model_id' => $problem]);
                }
                $previous = $record->image_path;
                if ($stored || $request->boolean('remove_image')) {
                    $record->image_path = $stored ?: null;
                }
                $record->fill($data)->save();
                $record->addModelInterfaces($model);

                return $record;
            }, 3);
        } catch (Throwable $error) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $error;
        }
        if ($previous && $previous !== $record->image_path) {
            Storage::disk('local')->delete($previous);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispositivo salvato.']);

        return to_route('companies.sites.devices.show', [$company, $site, $record, 'view' => $request->query('view') === 'all' ? 'all' : 'infrastructure']);
    }

    public function deletionImpact(Company $company, Site $site, Device $device): JsonResponse
    {
        return response()->json(DB::transaction(fn () => DeletionImpact::preview(DeletionImpact::device($company, $site, $device)), 3));
    }

    public function destroy(ConfirmDeletionRequest $request, Company $company, Site $site, Device $device): RedirectResponse
    {
        DB::transaction(function () use ($request, $company, $site, $device) {
            $impact = DeletionImpact::device($company, $site, $device);
            $request->ensureUnchanged($impact);
            DeletionImpact::deleteDevices([$device->id]);
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispositivo e documentazione degli IP collegati eliminati. Le esclusioni delle subnet restano valide.']);

        return to_route('companies.sites.devices.index', [$company, $site]);
    }
}

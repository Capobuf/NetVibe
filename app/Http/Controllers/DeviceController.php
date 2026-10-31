<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmDeletionRequest;
use App\Http\Requests\DeviceRequest;
use App\Models\CableEnd;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\IpAddress;
use App\Models\IpStatus;
use App\Models\Manufacturer;
use App\Models\ModelType;
use App\Models\Site;
use App\Support\DeletionImpact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    /** @return Collection<int, Manufacturer> */
    private function catalog(): Collection
    {
        return Manufacturer::query()->with(['models:id,manufacturer_id,name'])->orderBy('name')->get(['id', 'name']);
    }

    public function index(Company $company, Site $site): Response
    {
        return Inertia::render('devices/index', [
            'company' => $company->only('id', 'name'), 'site' => $site->only('id', 'name'),
            'manufacturers' => $this->catalog(),
            'types' => fn () => ModelType::query()->orderBy('name')->get(['id', 'name']),
            'devices' => $site->devices()->with(['manufacturer:id,name', 'model:id,name,model_type_id', 'model.modelType:id,name', 'ipAddresses:id,device_id,address'])->orderBy('name')->get()
                ->map(fn ($device) => [...$device->toArray(), 'ips' => $device->ipAddresses->map(fn ($ip) => long2ip($ip->address))]),
        ]);
    }

    public function show(Request $request, Company $company, Site $site, Device $device): Response
    {
        $options = $request->validate(['interface' => ['sometimes', 'integer', 'min:1']]);
        $selectedInterface = isset($options['interface']) ? $device->interfaces()->findOrFail($options['interface']) : null;
        $device->load(['manufacturer', 'model.modelType', 'interfaces', 'ipAddresses.subnet.vlan']);

        return Inertia::render('devices/show', [
            'company' => $company->only('id', 'name'), 'site' => $site->only('id', 'name'),
            'device' => $device,
            'initialInterfaceId' => $selectedInterface?->id,
            'assetUrls' => $device->model?->assetUrls() ?? [],
            'manufacturers' => $this->catalog(),
            'types' => fn () => ModelType::query()->orderBy('name')->get(['id', 'name']),
            'ips' => $device->ipAddresses->map(fn ($ip) => [...$ip->toArray(), 'ip' => long2ip($ip->address)]),
            'availableIps' => IpAddress::query()->whereIn('subnet_id', $site->subnets()->select('id'))
                ->where(fn ($query) => $query->whereNull('device_id')->orWhere(fn ($query) => $query->where('device_id', $device->id)->whereNull('device_interface_id')))
                ->with('subnet:id,cidr')->orderBy('address')->get()
                ->map(fn ($ip) => [...$ip->only('id', 'hostname', 'description', 'mac_address', 'subnet'), 'ip' => long2ip($ip->address)]),
            'subnets' => $site->subnets()->with('excludedRanges')->orderBy('network_address')->get(['id', 'cidr', 'name'])
                ->map(fn ($subnet) => [...$subnet->only('id', 'cidr', 'name'),
                    'excludedRanges' => $subnet->excludedRanges->map(fn ($range) => [
                        'id' => $range->id, 'start_ip' => long2ip($range->start_address), 'end_ip' => long2ip($range->end_address),
                    ]),
                ]),
            'statuses' => IpStatus::query()->orderBy('name')->get(['id', 'name']),
            'tags' => $company->tags()->orderBy('name')->get(['id', 'name', 'color']),
        ]);
    }

    public function store(DeviceRequest $request, Company $company, Site $site): RedirectResponse
    {
        return $this->save($request, $company, $site);
    }

    public function update(DeviceRequest $request, Company $company, Site $site, Device $device): RedirectResponse
    {
        return $this->save($request, $company, $site, $device);
    }

    private function save(DeviceRequest $request, Company $company, Site $site, ?Device $device = null): RedirectResponse
    {
        $data = $request->validated();
        $record = DB::transaction(function () use ($data, $company, $site, $device) {
            $model = DeviceModel::query()->sharedLock()->whereKey($data['device_model_id'])->firstOrFail();
            Manufacturer::query()->sharedLock()->findOrFail($model->manufacturer_id);
            $data['manufacturer_id'] = $model->manufacturer_id;
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $site = Site::query()->findOrFail($site->id);
            $record = $device ? $site->devices()->lockForUpdate()->findOrFail($device->id) : $site->devices()->make();
            if ($record->exists && $record->device_model_id !== $model->id && CableEnd::query()->where('device_id', $record->id)->exists()) {
                throw ValidationException::withMessages(['device_model_id' => 'Il dispositivo ha cavi collegati. Scollegali esplicitamente prima di cambiarne il modello.']);
            }
            if ($record->exists && $record->device_model_id !== $model->id && $record->rackItem()->exists()) {
                throw ValidationException::withMessages(['device_model_id' => 'Rimuovi prima il dispositivo dal rack per cambiarne il modello.']);
            }
            if ($record->exists && $record->device_model_id !== $model->id && $record->interfaces()->whereNotNull('connector_id')->exists()) {
                throw ValidationException::withMessages(['device_model_id' => 'Il dispositivo ha interfacce legate al pannello. Il cambio modello non può conservarne l’identità: mantieni il modello attuale.']);
            }
            $record->fill($data)->save();
            $record->addModelInterfaces($model);

            return $record;
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispositivo salvato.']);

        return to_route('companies.sites.devices.show', [$company, $site, $record]);
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

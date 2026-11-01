<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Support\InterfaceMac;
use App\Support\MikrotikPortMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DeviceInterfaceController extends Controller
{
    public function show(Company $company, Site $site, Device $device, DeviceInterface $interface): JsonResponse
    {
        $panel = $device->model?->panel;
        $ports = [...($panel['faces']['front']['ports'] ?? []), ...($panel['faces']['rear']['ports'] ?? [])];

        return response()->json([
            'interface' => $interface,
            'port' => collect($ports)->firstWhere('id', $interface->connector_id),
            'ips' => $interface->ipAddresses()->with('subnet:id,cidr')->orderBy('address')->get()
                ->map(fn ($ip) => [...$ip->only('id', 'hostname', 'mac_address', 'subnet'), 'ip' => long2ip($ip->address)]),
        ]);
    }

    public function store(Request $request, Company $company, Site $site, Device $device): RedirectResponse
    {
        return $this->save($request, $company, $device);
    }

    public function update(Request $request, Company $company, Site $site, Device $device, DeviceInterface $interface): RedirectResponse
    {
        return $this->save($request, $company, $device, $interface);
    }

    private function save(Request $request, Company $company, Device $device, ?DeviceInterface $interface = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => [$interface?->connector_id ? 'nullable' : 'required', 'string', 'max:255'],
            'kind' => [$interface?->connector_id ? 'exclude' : 'required', 'in:physical,logical'],
            'mac_address' => ['nullable', 'string', 'mac_address'],
            'mikrotik_source_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'mikrotik_edit_mode' => ['nullable', 'in:planned,manual'], 'mac_confirmation_token' => ['nullable', 'string', 'size:64'],
        ], [], ['name' => 'nome', 'kind' => 'tipo', 'mac_address' => 'MAC']);
        DB::transaction(function () use ($company, $device, $interface, $data) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $hasSource = array_key_exists('mikrotik_source_id', $data);
            $sourceId = $data['mikrotik_source_id'] ?? null;
            unset($data['mikrotik_source_id']);
            $source = null;
            if ($hasSource && $sourceId) {
                $source = $device->mikrotikSources()->where('kind', 'interface')->lockForUpdate()->find($sourceId);
                $connection = $device->mikrotikConnection;
                $unchanged = $interface && $source?->device_interface_id === $interface->id;
                if (! $source || (! $unchanged && (! $connection?->activated_at || $connection->sync_status !== 'ok'
                    || $source->generation !== $connection->source_generation || $source->state !== 'current' || $source->problem))
                    || ($source->device_interface_id && ! $unchanged)
                    || ($data['kind'] ?? $interface?->kind) !== 'physical') {
                    throw ValidationException::withMessages(['mikrotik_source_id' => 'Scegli una porta MikroTik aggiornata e non già associata di questo dispositivo.']);
                }
            }
            if ($source && $interface?->connector_id) {
                $port = collect($device->model?->connectors() ?? [])->firstWhere('id', $interface->connector_id);
                if (! $port || MikrotikPortMap::type($source->data['default_name'] ?? '') !== $port['type']) {
                    throw ValidationException::withMessages(['mikrotik_source_id' => 'L’interfaccia MikroTik non è compatibile con il tipo di porta. Scegli un’interfaccia compatibile o rimuovi l’associazione.']);
                }
            }
            if ($interface) {
                $record = $device->interfaces()->lockForUpdate()->findOrFail($interface->id);
                if (($data['kind'] ?? $record->kind) !== 'physical' && ! $hasSource && $record->mikrotikSource) {
                    throw ValidationException::withMessages(['kind' => 'Rimuovi l’associazione alla porta MikroTik prima di cambiare tipo.']);
                }
                InterfaceMac::synchronize($record, $data['mac_address'] ?? null, null, $data['mac_confirmation_token'] ?? null, $data['mikrotik_edit_mode'] ?? null);
                unset($data['mac_confirmation_token'], $data['mikrotik_edit_mode']);
                $record->update($data);
            } else {
                unset($data['mac_confirmation_token'], $data['mikrotik_edit_mode']);
                $record = $device->interfaces()->create($data);
            }
            if ($hasSource) {
                $device->mikrotikSources()->where('device_interface_id', $record->id)
                    ->when($source, fn ($query) => $query->where('id', '!=', $source->id))->update(['device_interface_id' => null, 'interface_from_model' => false]);
                if ($source) {
                    if ($source->device_interface_id !== $record->id) {
                        $source->interface_from_model = false;
                    }
                    $source->device_interface_id = $record->id;
                    $source->save();
                }
            }
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Interfaccia salvata.']);

        return back();
    }

    public function destroy(Request $request, Company $company, Site $site, Device $device, DeviceInterface $interface): RedirectResponse
    {
        $data = $request->validate(['confirmed' => ['accepted'], 'ip_ids' => ['sometimes', 'array'], 'ip_ids.*' => ['integer', 'distinct']]);
        DB::transaction(function () use ($company, $device, $interface, $data) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $interface = $device->interfaces()->findOrFail($interface->id);
            if ($interface->connector_id) {
                throw ValidationException::withMessages(['confirmed' => 'Questa interfaccia rappresenta una porta del modello: puoi modificarne alias e MAC o scollegare i suoi IP.']);
            }
            $ipIds = $interface->ipAddresses()->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $confirmedIds = array_map('intval', $data['ip_ids'] ?? []);
            sort($confirmedIds);
            if ($ipIds !== $confirmedIds) {
                throw ValidationException::withMessages(['confirmed' => 'Gli IP collegati sono cambiati. Ricarica la pagina e verifica il nuovo elenco prima di eliminare l’interfaccia.']);
            }
            $interface->ipAddresses()->delete();
            $interface->delete();
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Interfaccia e documentazione degli IP collegati eliminate. Le esclusioni delle subnet restano valide.']);

        return back();
    }
}

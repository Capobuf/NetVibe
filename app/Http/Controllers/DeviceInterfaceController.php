<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Support\InterfaceMac;
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
            'mikrotik_edit_mode' => ['nullable', 'in:planned,manual'], 'mac_confirmation_token' => ['nullable', 'string', 'size:64'],
        ], [], ['name' => 'nome', 'kind' => 'tipo', 'mac_address' => 'MAC']);
        DB::transaction(function () use ($company, $device, $interface, $data) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $device = Device::query()->findOrFail($device->id);
            if ($interface) {
                $record = $device->interfaces()->lockForUpdate()->findOrFail($interface->id);
                InterfaceMac::synchronize($record, $data['mac_address'] ?? null, null, $data['mac_confirmation_token'] ?? null, $data['mikrotik_edit_mode'] ?? null);
                unset($data['mac_confirmation_token'], $data['mikrotik_edit_mode']);
                $record->update($data);
            } else {
                unset($data['mac_confirmation_token'], $data['mikrotik_edit_mode']);
                $device->interfaces()->create($data);
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

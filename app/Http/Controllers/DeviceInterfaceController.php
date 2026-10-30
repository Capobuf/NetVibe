<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DeviceInterfaceController extends Controller
{
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
        ], [], ['name' => 'nome', 'kind' => 'tipo', 'mac_address' => 'MAC']);
        DB::transaction(function () use ($company, $device, $interface, $data) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $device = Device::query()->findOrFail($device->id);
            if ($interface) {
                $device->interfaces()->findOrFail($interface->id)->update($data);
            } else {
                $device->interfaces()->create($data);
            }
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Interfaccia salvata.']);

        return back();
    }

    public function destroy(Request $request, Company $company, Site $site, Device $device, DeviceInterface $interface): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        DB::transaction(function () use ($company, $device, $interface) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $interface = $device->interfaces()->findOrFail($interface->id);
            if ($interface->connector_id) {
                throw ValidationException::withMessages(['confirmed' => 'Questa interfaccia rappresenta una porta del modello: puoi modificarne alias e MAC o scollegare i suoi IP.']);
            }
            $interface->ipAddresses()->update(['device_id' => null, 'device_interface_id' => null]);
            $interface->delete();
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Interfaccia eliminata. Gli IP sono stati conservati.']);

        return back();
    }
}

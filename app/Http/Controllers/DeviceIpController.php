<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\IpAddress;
use App\Models\Site;
use App\Support\DeviceAssignment;
use App\Support\InterfaceMac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DeviceIpController extends Controller
{
    public function store(Request $request, Company $company, Site $site, Device $device): RedirectResponse
    {
        $data = $request->validate(['ip_address_id' => ['required', 'integer'], 'device_interface_id' => ['nullable', 'integer'], 'mac_confirmation_token' => ['nullable', 'string', 'size:64']]);
        DB::transaction(function () use ($company, $site, $device, $data) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $ip = IpAddress::query()->whereIn('subnet_id', $site->subnets()->select('id'))->lockForUpdate()->whereKey($data['ip_address_id'])->first();
            if (! $ip || ($ip->device_id && $ip->device_id !== $device->id)) {
                throw ValidationException::withMessages(['ip_address_id' => 'Scegli un IP documentato di questa sede, non collegato a un altro dispositivo.']);
            }
            $assignment = DeviceAssignment::validate($site->id, $device->id, $data['device_interface_id'] ?? null, $ip);
            if ($assignment['device_interface_id']) {
                $interface = DeviceInterface::query()->lockForUpdate()->findOrFail($assignment['device_interface_id']);
                $assignment['mac_address'] = InterfaceMac::synchronize($interface, $ip->mac_address, $ip, $data['mac_confirmation_token'] ?? null);
            }
            $ip->update($assignment);
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'IP collegato.']);

        return back();
    }

    public function destroy(Request $request, Company $company, Site $site, Device $device, IpAddress $ipAddress): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        $excluded = DB::transaction(function () use ($company, $device, $ipAddress) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $record = $device->ipAddresses()->lockForUpdate()->findOrFail($ipAddress->id);
            $excluded = $record->subnet->isExcluded($record->address);
            $record->delete();

            return $excluded;
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => $excluded
            ? 'Documentazione rimossa. L’indirizzo resta escluso dai suggerimenti.' : 'IP liberato e documentazione rimossa.']);

        return back();
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\IpAddressRequest;
use App\Models\Company;
use App\Models\DeviceInterface;
use App\Models\IpAddress;
use App\Models\IpStatus;
use App\Models\MikrotikSource;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\UnifiObservation;
use App\Support\DeviceAssignment;
use App\Support\InterfaceMac;
use App\Support\Ipv4Ranges;
use App\Support\MikrotikIpam;
use App\Support\UnifiIpam;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class IpAddressController extends Controller
{
    public function store(IpAddressRequest $request, Company $company, Site $site, Subnet $subnet): RedirectResponse
    {
        return $this->save($request, $company, $subnet);
    }

    public function update(IpAddressRequest $request, Company $company, Site $site, Subnet $subnet, IpAddress $ipAddress): RedirectResponse
    {
        return $this->save($request, $company, $subnet, $ipAddress);
    }

    public function destroy(Request $request, Company $company, Site $site, Subnet $subnet, IpAddress $ipAddress): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']], [
            'confirmed.accepted' => 'Conferma esplicitamente la liberazione dell’IP e la cancellazione dei suoi dati.',
        ]);
        $excluded = DB::transaction(function () use ($company, $subnet, $ipAddress) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $record = $subnet->ipAddresses()->lockForUpdate()->findOrFail($ipAddress->id);
            $excluded = $subnet->isExcluded($record->address)
                || MikrotikSource::blocking($subnet->site_id)->where('address', $record->address)->exists()
                || UnifiObservation::blocking($subnet->site_id)->where('address', $record->address)->exists();
            $record->delete();

            return $excluded;
        }, 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => $excluded
            ? 'Documentazione rimossa. L’indirizzo resta escluso dai suggerimenti.' : 'IP liberato e documentazione rimossa.']);

        return back();
    }

    private function save(IpAddressRequest $request, Company $company, Subnet $subnet, ?IpAddress $ipAddress = null): RedirectResponse
    {
        $data = $request->validated();
        $address = ip2long($data['ip']);

        try {
            $excluded = DB::transaction(function () use ($company, $subnet, $ipAddress, $data, $address) {
                // Keep a chosen status alive until the IP write commits.
                if (isset($data['ip_status_id']) && ! IpStatus::query()->whereKey($data['ip_status_id'])->sharedLock()->first()) {
                    throw ValidationException::withMessages(['ip_status_id' => 'Lo stato scelto è stato eliminato. Scegli un altro stato.']);
                }
                // Serialize tag creation with company deletion and other tag writers.
                $company = Company::query()->lockForUpdate()->findOrFail($company->id);
                // Keep the CIDR stable during the write; uniqueness is enforced by the database.
                $subnet = Subnet::query()->sharedLock()->findOrFail($subnet->id);
                [$first, $last] = Ipv4Ranges::usableBounds($subnet->network_address, $subnet->broadcast_address);
                if ($address < $first || $address > $last) {
                    throw ValidationException::withMessages(['ip' => "Inserisci un indirizzo host utilizzabile nella subnet {$subnet->cidr}, da ".long2ip($first).' a '.long2ip($last).'.']);
                }

                $record = $ipAddress
                    ? $subnet->ipAddresses()->lockForUpdate()->findOrFail($ipAddress->id)
                    : $subnet->ipAddresses()->make();
                $assignment = DeviceAssignment::validate($subnet->site_id, $data['device_id'] ?? null, $data['device_interface_id'] ?? null, $record);
                MikrotikIpam::manualChange($record, ['address' => $address, 'mac_address' => $data['mac_address'] ?? null, ...$assignment], $data['mikrotik_edit_mode'] ?? null);
                UnifiIpam::manualChange($record, ['address' => $address, 'mac_address' => $data['mac_address'] ?? null], $data['mikrotik_edit_mode'] ?? null);
                $record->address = $address;
                $mac = $data['mac_address'] ?? null;
                if ($assignment['device_interface_id']) {
                    $interface = DeviceInterface::query()->lockForUpdate()->findOrFail($assignment['device_interface_id']);
                    $mac = InterfaceMac::synchronize($interface, $mac, $record, $data['mac_confirmation_token'] ?? null, $data['mikrotik_edit_mode'] ?? null);
                }
                $record->fill([
                    ...$assignment,
                    'address' => $address,
                    'description' => $data['description'] ?? null,
                    'hostname' => $data['hostname'] ?? null,
                    'mac_address' => $mac,
                    'ip_status_id' => $data['ip_status_id'] ?? null,
                ])->save();

                $tagIds = [];
                foreach ($data['tags'] ?? [] as $tag) {
                    $company->tags()->whereKey($tag['id'])->update(['color' => $tag['color']]);
                    $tagIds[] = $tag['id'];
                }
                foreach ($data['new_tags'] ?? [] as $tag) {
                    $tagIds[] = $company->tags()->firstOrCreate(['name' => $tag['name']], ['color' => $tag['color']])->id;
                }
                $record->tags()->sync(array_unique($tagIds));

                return $subnet->isExcluded($address) || MikrotikSource::blocking($subnet->site_id)->where('address', $address)->exists()
                    || UnifiObservation::blocking($subnet->site_id)->where('address', $address)->exists();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'ip' => 'Questo indirizzo è già documentato nella subnet. Apri il record esistente oppure scegli un altro IP.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => $excluded ? 'warning' : 'success',
            'message' => $excluded ? 'IP salvato. L’indirizzo resta escluso dai suggerimenti.'
                : ($ipAddress ? 'IP documentato aggiornato.' : 'IP documentato.'),
        ]);

        return back();
    }
}

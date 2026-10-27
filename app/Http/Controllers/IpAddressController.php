<?php

namespace App\Http\Controllers;

use App\Http\Requests\IpAddressRequest;
use App\Models\Company;
use App\Models\IpAddress;
use App\Models\Site;
use App\Models\Subnet;
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
        return $this->save($request, $subnet);
    }

    public function update(IpAddressRequest $request, Company $company, Site $site, Subnet $subnet, IpAddress $ipAddress): RedirectResponse
    {
        return $this->save($request, $subnet, $ipAddress);
    }

    public function destroy(Request $request, Company $company, Site $site, Subnet $subnet, IpAddress $ipAddress): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']], [
            'confirmed.accepted' => 'Conferma esplicitamente la liberazione dell’IP e la cancellazione dei suoi dati.',
        ]);
        $excluded = $subnet->isExcluded($ipAddress->address);
        $ipAddress->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => $excluded
            ? 'Documentazione rimossa. L’indirizzo resta escluso dai suggerimenti.' : 'Documentazione dell’IP rimossa.']);

        return back();
    }

    private function save(IpAddressRequest $request, Subnet $subnet, ?IpAddress $ipAddress = null): RedirectResponse
    {
        $data = $request->validated();
        $address = ip2long($data['ip']);

        try {
            $excluded = DB::transaction(function () use ($subnet, $ipAddress, $data, $address) {
                // Keep the CIDR stable during the write; uniqueness is enforced by the database.
                $subnet = Subnet::query()->sharedLock()->findOrFail($subnet->id);
                if ($address < $subnet->network_address || $address > $subnet->broadcast_address) {
                    throw ValidationException::withMessages(['ip' => "L’indirizzo deve appartenere alla subnet {$subnet->cidr}."]);
                }

                $record = $ipAddress
                    ? $subnet->ipAddresses()->findOrFail($ipAddress->id)
                    : $subnet->ipAddresses()->make();
                $record->fill([
                    'address' => $address,
                    'description' => $data['description'] ?? null,
                    'hostname' => $data['hostname'] ?? null,
                    'mac_address' => $data['mac_address'] ?? null,
                ])->save();

                return $subnet->isExcluded($address);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'ip' => 'Questo indirizzo è già documentato nella subnet. Apri il record esistente oppure scegli un altro IP.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => $excluded ? 'warning' : 'success',
            'message' => $excluded ? 'IP salvato. L’indirizzo appartiene a un intervallo escluso dai suggerimenti.'
                : ($ipAddress ? 'IP documentato aggiornato.' : 'IP documentato.'),
        ]);

        return back();
    }
}

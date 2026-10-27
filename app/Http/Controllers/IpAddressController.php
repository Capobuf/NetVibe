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
        $excluded = $subnet->isExcluded($ipAddress->address);
        $ipAddress->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => $excluded
            ? 'Documentazione rimossa. L’indirizzo resta escluso dai suggerimenti.' : 'Documentazione dell’IP rimossa.']);

        return back();
    }

    private function save(IpAddressRequest $request, Company $company, Subnet $subnet, ?IpAddress $ipAddress = null): RedirectResponse
    {
        $data = $request->validated();
        $address = ip2long($data['ip']);

        try {
            $excluded = DB::transaction(function () use ($company, $subnet, $ipAddress, $data, $address) {
                // Serialize tag creation with company deletion and other tag writers.
                $company = Company::query()->lockForUpdate()->findOrFail($company->id);
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

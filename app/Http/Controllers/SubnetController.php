<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubnetRequest;
use App\Models\Company;
use App\Models\IpAddress;
use App\Models\Site;
use App\Models\Subnet;
use App\Support\Ipv4Cidr;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SubnetController extends Controller
{
    public function index(Company $company, Site $site): Response
    {
        return Inertia::render('subnets/index', [
            'company' => $company->only('id', 'name'),
            'site' => $site->only('id', 'name'),
            'vlans' => $site->vlans()->orderBy('tag')->get(['id', 'tag', 'name']),
            'subnets' => $site->subnets()->orderBy('network_address')
                ->get(['id', 'cidr', 'name', 'description', 'vlan_id']),
        ]);
    }

    public function store(SubnetRequest $request, Company $company, Site $site): RedirectResponse
    {
        return $this->save($request, $site);
    }

    public function show(Company $company, Site $site, Subnet $subnet): Response
    {
        return Inertia::render('subnets/show', [
            'company' => $company->only('id', 'name'),
            'site' => $site->only('id', 'name'),
            'subnet' => $subnet->only('id', 'cidr', 'name', 'description', 'vlan_id'),
            'vlan' => $subnet->vlan?->only('id', 'tag', 'name'),
            'ipAddresses' => $subnet->ipAddresses()->orderBy('address')->paginate(50)
                ->through(fn (IpAddress $ipAddress) => [
                    ...$ipAddress->only('id', 'description', 'hostname', 'mac_address'),
                    'ip' => long2ip($ipAddress->address),
                ]),
        ]);
    }

    public function update(SubnetRequest $request, Company $company, Site $site, Subnet $subnet): RedirectResponse
    {
        return $this->save($request, $site, $subnet);
    }

    private function save(SubnetRequest $request, Site $site, ?Subnet $subnet = null): RedirectResponse
    {
        $data = $request->validated();
        $range = Ipv4Cidr::parse($data['cidr']);

        try {
            DB::transaction(function () use ($data, $range, $site, $subnet) {
                // Lock the existing parent even when it has no subnets yet.
                $site = Site::query()->lockForUpdate()->findOrFail($site->id);
                $record = $subnet ? $site->subnets()->lockForUpdate()->findOrFail($subnet->id) : $site->subnets()->make();
                if ($subnet && $record->ipAddresses()->where(function ($query) use ($range) {
                    $query->where('address', '<', $range['network_address'])
                        ->orWhere('address', '>', $range['broadcast_address']);
                })->exists()) {
                    throw ValidationException::withMessages([
                        'cidr' => 'Il nuovo CIDR lascerebbe fuori IP documentati. Modifica o libera prima gli indirizzi interessati.',
                    ]);
                }
                $overlap = $site->subnets()
                    ->when($subnet, fn ($query) => $query->where('id', '!=', $subnet->id))
                    ->where('network_address', '<=', $range['broadcast_address'])
                    ->where('broadcast_address', '>=', $range['network_address'])
                    ->first();

                if ($overlap) {
                    throw ValidationException::withMessages([
                        'cidr' => "La rete {$range['cidr']} si sovrappone a {$overlap->cidr} in questa sede, anche se la VLAN è diversa.",
                    ]);
                }

                $vlanId = $data['vlan_id'] ?? null;
                if ($vlanId !== null && ! $site->vlans()->whereKey($vlanId)->exists()) {
                    throw ValidationException::withMessages(['vlan_id' => 'Scegli una VLAN appartenente a questa sede.']);
                }

                if (isset($data['new_vlan'])) {
                    $vlanId = $site->vlans()->create($data['new_vlan'])->id;
                }

                $record->fill([
                    ...$range,
                    'name' => $data['name'] ?? null,
                    'description' => $data['description'] ?? null,
                    'vlan_id' => $vlanId,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'new_vlan.tag' => 'Questo ID VLAN è già presente in questa sede. Seleziona la VLAN esistente.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => ($subnet ? 'Subnet aggiornata: ' : 'Subnet creata: ').$range['cidr'].'.',
        ]);

        return back();
    }
}

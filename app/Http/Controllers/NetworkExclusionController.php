<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Device;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NetworkExclusionController extends Controller
{
    public function subnetPreview(Company $company, Site $site, Subnet $subnet): JsonResponse
    {
        return $this->preview($subnet);
    }

    public function vlanPreview(Company $company, Site $site, Vlan $vlan): JsonResponse
    {
        return $this->preview($vlan);
    }

    public function subnet(Request $request, Company $company, Site $site, Subnet $subnet): RedirectResponse
    {
        return $this->save($request, $site, $subnet);
    }

    public function vlan(Request $request, Company $company, Site $site, Vlan $vlan): RedirectResponse
    {
        return $this->save($request, $site, $vlan);
    }

    public function sourcePreview(Company $company, Site $site, Device $device, int $source): JsonResponse
    {
        $source = $device->mikrotikSources()->whereIn('kind', ['subnet', 'vlan'])->findOrFail($source);

        return response()->json([
            'label' => $source->kind === 'vlan' ? 'VLAN '.$source->data['tag'] : $source->data['cidr'],
            'excluded_at' => $source->excluded_at, 'inherited_vlan' => null, 'subnets' => [],
            'source' => $device->name,
        ]);
    }

    public function source(Request $request, Company $company, Site $site, Device $device, int $source): RedirectResponse
    {
        $data = $request->validate(['excluded' => ['required', 'boolean']]);
        DB::transaction(function () use ($site, $device, $source, $data) {
            Site::query()->lockForUpdate()->findOrFail($site->id);
            $source = $device->mikrotikSources()->whereIn('kind', ['subnet', 'vlan'])->lockForUpdate()->findOrFail($source);
            if ($source->subnet_id || $source->vlan_id) {
                throw ValidationException::withMessages(['excluded' => 'La fonte è associata a una rete. Ricarica la pagina e gestisci l’esclusione dalla rete.']);
            }
            $source->excluded_at = $data['excluded'] ? ($source->excluded_at ?? now()) : null;
            $source->save();
        }, 3);

        return back();
    }

    private function preview(Subnet|Vlan $network): JsonResponse
    {
        $subnets = $network instanceof Vlan ? $network->subnets()->orderBy('network_address') : Subnet::whereKey($network->id);

        return response()->json([
            'kind' => $network instanceof Vlan ? 'vlan' : 'subnet',
            'label' => $network instanceof Vlan ? 'VLAN '.$network->tag : $network->cidr,
            'excluded_at' => $network->excluded_at,
            'inherited_vlan' => $network instanceof Subnet && $network->vlan?->excluded_at ? $network->vlan->only('id', 'tag') : null,
            'subnets' => $subnets->withCount('ipAddresses')->get(['id', 'cidr', 'name', 'excluded_at']),
        ]);
    }

    private function save(Request $request, Site $site, Subnet|Vlan $network): RedirectResponse
    {
        $data = $request->validate(['excluded' => ['required', 'boolean']]);
        DB::transaction(function () use ($site, $network, $data) {
            Site::query()->lockForUpdate()->findOrFail($site->id);
            $network = $network->newQuery()->lockForUpdate()->findOrFail($network->id);
            if ($network instanceof Subnet && $network->vlan?->excluded_at) {
                throw ValidationException::withMessages(['excluded' => 'Reincludi prima la VLAN '.$network->vlan->tag.'.']);
            }
            $network->excluded_at = $data['excluded'] ? ($network->excluded_at ?? now()) : null;
            $network->save();
        }, 3);

        return back();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Site;
use App\Models\UnifiObservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UnifiIpamController extends Controller
{
    public function update(Request $request, Company $company, Site $site, int $observation): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:update,keep']]);
        DB::transaction(function () use ($site, $observation, $data) {
            $site = Site::query()->lockForUpdate()->findOrFail($site->id);
            $source = UnifiObservation::forSite($site->id)->with('unifiSite.unifiConnection')->lockForUpdate()->findOrFail($observation);
            if (! $source->display()['can_update']) {
                throw ValidationException::withMessages(['decision' => 'Il dato sorgente non è aggiornabile. Sincronizza e verifica la rete.']);
            }
            if ($data['decision'] === 'update') {
                $vlan = $source->vlan_id ? $site->vlans()->visible()->lockForUpdate()->findOrFail($source->vlan_id) : null;
                if ($source->problem === 'VLAN documentata diversa dalla fonte.') {
                    $subnet = $site->subnets()->visible()->lockForUpdate()->findOrFail($source->subnet_id);
                    if ($subnet->cidr !== $source->data['cidr']) {
                        throw ValidationException::withMessages(['decision' => 'La subnet è cambiata. Ricarica la pagina.']);
                    }
                    $subnet->update(['vlan_id' => $vlan?->id]);
                } else {
                    $vlan?->update(['name' => $source->data['name']]);
                }
                $source->problem = null;
                $source->decision = null;
            } else {
                $source->decision = 'keep';
            }
            $source->save();
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['decision'] === 'keep' ? 'Documentazione mantenuta.' : 'Documentazione aggiornata.']);

        return back();
    }
}

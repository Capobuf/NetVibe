<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmDeletionRequest;
use App\Http\Requests\VlanRequest;
use App\Models\Company;
use App\Models\Site;
use App\Models\Vlan;
use App\Support\DeletionImpact;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VlanController extends Controller
{
    public function index(Company $company, Site $site): Response
    {
        return Inertia::render('vlans/index', [
            'company' => $company->only('id', 'name'),
            'site' => $site->only('id', 'name'),
            'vlans' => $site->vlans()->select('id', 'tag', 'name')->withCount('subnets')
                ->with(['subnets' => fn ($query) => $query->where('site_id', $site->id)
                    ->orderBy('network_address')->select('id', 'vlan_id', 'cidr', 'name', 'description')])
                ->orderBy('tag')->get(),
        ]);
    }

    public function store(VlanRequest $request, Company $company, Site $site): RedirectResponse
    {
        return $this->save($request, $site);
    }

    public function show(Company $company, Site $site, Vlan $vlan): Response
    {
        return Inertia::render('vlans/show', [
            'company' => $company->only('id', 'name'),
            'site' => $site->only('id', 'name'),
            'vlan' => $vlan->only('id', 'tag', 'name'),
            'subnets' => $site->subnets()->where('vlan_id', $vlan->id)
                ->orderBy('network_address')->get(['id', 'cidr', 'name', 'description', 'vlan_id']),
        ]);
    }

    public function update(VlanRequest $request, Company $company, Site $site, Vlan $vlan): RedirectResponse
    {
        return $this->save($request, $site, $vlan);
    }

    public function deletionImpact(Company $company, Site $site, Vlan $vlan): JsonResponse
    {
        return response()->json(DB::transaction(fn () => DeletionImpact::preview(DeletionImpact::networks($company, $site, vlan: $vlan)), 3));
    }

    public function destroy(ConfirmDeletionRequest $request, Company $company, Site $site, Vlan $vlan): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:keep_subnets,delete_subnets']]);

        DB::transaction(function () use ($request, $company, $site, $vlan, $data) {
            $impact = DeletionImpact::networks($company, $site, vlan: $vlan);
            $request->ensureUnchanged($impact);
            if ($data['mode'] === 'delete_subnets') {
                DeletionImpact::deleteSubnets(array_column($impact['subnets'], 'id'));
            } else {
                $vlan->subnets()->update(['vlan_id' => null]);
            }
            $vlan->delete();
        }, 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['mode'] === 'delete_subnets'
            ? 'VLAN e subnet associate eliminate.' : 'VLAN eliminata. Subnet e documentazione conservate senza VLAN.']);

        return to_route('companies.sites.vlans.index', [$company, $site]);
    }

    private function save(VlanRequest $request, Site $site, ?Vlan $vlan = null): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $site, $vlan) {
                $site = Site::query()->lockForUpdate()->findOrFail($site->id);
                $record = $vlan ? $site->vlans()->findOrFail($vlan->id) : $site->vlans()->make();
                $record->fill($request->validated())->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['tag' => 'Questo ID VLAN è già presente in questa sede.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $vlan ? 'VLAN aggiornata.' : 'VLAN creata.']);

        return back();
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\VlanRequest;
use App\Models\Company;
use App\Models\Site;
use App\Models\Vlan;
use Illuminate\Database\UniqueConstraintViolationException;
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
            'vlans' => $site->vlans()->select('id', 'tag', 'name')->withCount('subnets')->orderBy('tag')->get(),
        ]);
    }

    public function store(VlanRequest $request, Company $company, Site $site): RedirectResponse
    {
        return $this->save($request, $site);
    }

    public function update(VlanRequest $request, Company $company, Site $site, Vlan $vlan): RedirectResponse
    {
        return $this->save($request, $site, $vlan);
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

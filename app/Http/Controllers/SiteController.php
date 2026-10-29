<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmDeletionRequest;
use App\Models\Company;
use App\Models\Site;
use App\Support\DeletionImpact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SiteController extends Controller
{
    public function store(Request $request, Company $company): RedirectResponse
    {
        DB::transaction(function () use ($request, $company) {
            // Serialize the transition to multiple sites without replacing the original site.
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $sites = $company->sites()->orderBy('id')->get();

            if ($sites->count() > 1 && $request->has('existing_site_name')) {
                throw ValidationException::withMessages([
                    'existing_site_name' => 'Le sedi sono cambiate. Chiudi e ricarica la pagina prima di aggiungere una sede.',
                ]);
            }

            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'existing_site_name' => $sites->count() === 1
                    ? ['required', 'string', 'max:255']
                    : ['exclude'],
            ], [], ['existing_site_name' => 'nome della sede esistente']);

            if ($sites->count() === 1) {
                $sites->first()->update(['name' => $data['existing_site_name']]);
            }

            return $company->sites()->create(['name' => $data['name']]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sede aggiunta.']);

        return to_route($request->routeIs('administration.*') ? 'administration.companies.show' : 'companies.settings.show', $company);
    }

    public function update(Request $request, Company $company, Site $site): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        DB::transaction(function () use ($data, $company, $site) {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $company->sites()->findOrFail($site->id)->update($data);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sede aggiornata.']);

        return to_route($request->routeIs('administration.*') ? 'administration.companies.show' : 'companies.settings.show', $company);
    }

    public function deletionImpact(Company $company, Site $site): JsonResponse
    {
        return response()->json(DB::transaction(fn () => DeletionImpact::preview(DeletionImpact::networks($company, $site)), 3));
    }

    public function destroy(ConfirmDeletionRequest $request, Company $company, Site $site): RedirectResponse
    {
        DB::transaction(function () use ($request, $company, $site) {
            $impact = DeletionImpact::networks($company, $site);
            $request->ensureUnchanged($impact);

            if (count($impact['sites']) === 1) {
                throw ValidationException::withMessages([
                    'confirmed' => 'L’azienda deve conservare una sede.',
                ]);
            }

            DeletionImpact::deleteSubnets(array_column($impact['subnets'], 'id'));
            $site->vlans()->delete();
            $company->sites()->findOrFail($site->id)->delete();
        }, 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sede eliminata.']);

        return to_route($request->routeIs('administration.*') ? 'administration.companies.show' : 'companies.settings.show', $company);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmDeletionRequest;
use App\Models\Company;
use App\Models\Site;
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

        return to_route('administration.companies.show', $company);
    }

    public function update(Request $request, Company $company, Site $site): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        DB::transaction(function () use ($data, $company, $site) {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $company->sites()->findOrFail($site->id)->update($data);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sede aggiornata.']);

        return to_route('administration.companies.show', $company);
    }

    public function destroy(ConfirmDeletionRequest $request, Company $company, Site $site): RedirectResponse
    {
        DB::transaction(function () use ($request, $company, $site) {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $sites = $company->sites()->lockForUpdate()->get();
            $request->ensureSitesUnchanged($sites);

            if ($sites->count() === 1) {
                throw ValidationException::withMessages([
                    'confirmed' => 'L’azienda deve conservare una sede. Per rimuovere tutto, elimina l’azienda dal suo modale di modifica.',
                ]);
            }

            $company->sites()->findOrFail($site->id)->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sede eliminata.']);

        return to_route('administration.companies.show', $company);
    }
}

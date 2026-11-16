<?php

namespace App\Http\Controllers;

use App\Http\Requests\CableRequest;
use App\Models\Company;
use App\Models\Site;
use App\Support\RackCabling;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Cables documented outside the rack editor, e.g. between devices on a desk or behind an unmanaged switch. */
class CableController extends Controller
{
    public function store(CableRequest $request, Company $company, Site $site): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $company, $site) {
                Company::query()->lockForUpdate()->findOrFail($company->id);
                RackCabling::add($site, $request->validated());
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['cables' => 'Una porta è già occupata. Ricarica la pagina prima di riprovare.']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Collegamento documentato.']);

        return back();
    }

    public function destroy(Request $request, Company $company, Site $site, int $cableId): RedirectResponse
    {
        $data = $request->validate(['cabling_revision' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($data, $company, $site, $cableId) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            RackCabling::remove($site, $cableId, $data['cabling_revision']);
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Collegamento rimosso.']);

        return back();
    }
}

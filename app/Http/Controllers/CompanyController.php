<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompanyRequest;
use App\Http\Requests\ConfirmDeletionRequest;
use App\Models\Company;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $options = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['sometimes', 'in:name,sites_count'],
            'direction' => ['sometimes', 'in:asc,desc'],
        ]);
        $search = $options['search'] ?? '';
        $sort = $options['sort'] ?? 'name';
        $direction = $options['direction'] ?? 'asc';

        return Inertia::render('companies/index', [
            'companies' => Company::query()->select('id', 'name')->withCount('sites')
                ->with(['sites' => fn ($query) => $query->orderBy('id')->select('id', 'company_id', 'name')])
                ->when($search !== '', fn ($query) => $query->whereRaw(
                    "name LIKE ? ESCAPE '!'",
                    ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%'],
                ))
                ->orderBy($sort, $direction)->orderBy('id')->paginate(20)->withQueryString(),
            'query' => compact('search', 'sort', 'direction'),
            'administration' => $request->routeIs('administration.*'),
        ]);
    }

    public function store(CompanyRequest $request): RedirectResponse
    {
        $company = DB::transaction(function () use ($request) {
            $company = Company::create($request->validated());
            $company->sites()->create(['name' => null]);

            return $company;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Azienda creata.']);

        return to_route('administration.companies.show', $company);
    }

    public function show(Request $request, Company $company, ?Site $site = null): Response
    {
        $sites = $company->sites()->orderBy('id')->get(['id', 'name']);

        return Inertia::render('companies/show', [
            'company' => $company->only('id', 'name'),
            'sites' => $sites,
            'selectedSite' => $site?->only('id', 'name') ?? $sites->firstOrFail()->only('id', 'name'),
            'administration' => $request->routeIs('administration.*'),
        ]);
    }

    public function update(CompanyRequest $request, Company $company): RedirectResponse
    {
        DB::transaction(function () use ($request, $company) {
            Company::query()->lockForUpdate()->findOrFail($company->id)->update($request->validated());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Azienda aggiornata.']);

        return back();
    }

    public function destroy(ConfirmDeletionRequest $request, Company $company): RedirectResponse
    {
        DB::transaction(function () use ($request, $company) {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $sites = $company->sites()->lockForUpdate()->get();
            $request->ensureSitesUnchanged($sites);

            $company->sites()->delete();
            $company->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Azienda eliminata.']);

        return to_route('administration.companies.index');
    }
}

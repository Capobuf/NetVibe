<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompanyRequest;
use App\Http\Requests\ConfirmDeletionRequest;
use App\Models\Company;
use App\Models\Site;
use App\Support\DeletionImpact;
use Illuminate\Http\JsonResponse;
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

    public function show(Request $request, Company $company, ?Site $site = null): Response|RedirectResponse
    {
        $sites = $company->sites()->orderBy('id')->get(['id', 'name']);

        if (! $request->routeIs('administration.*')) {
            return to_route('companies.sites.subnets.index', [$company, $site ?? $sites->firstOrFail()]);
        }

        return Inertia::render('companies/show', [
            'company' => $company->only('id', 'name'),
            'sites' => $sites,
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

    public function deletionImpact(Company $company): JsonResponse
    {
        return response()->json(DB::transaction(fn () => DeletionImpact::preview(DeletionImpact::networks($company)), 3));
    }

    public function destroy(ConfirmDeletionRequest $request, Company $company): RedirectResponse
    {
        DB::transaction(function () use ($request, $company) {
            $impact = DeletionImpact::networks($company);
            $request->ensureUnchanged($impact);
            DeletionImpact::deleteSubnets(array_column($impact['subnets'], 'id'));
            DB::table('vlans')->whereIn('site_id', array_column($impact['sites'], 'id'))->delete();
            $company->sites()->delete();
            $company->delete();
        }, 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Azienda eliminata.']);

        return to_route('administration.companies.index');
    }
}

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
    /** There is no company list: users always work inside a company. */
    public function index(Request $request): Response|RedirectResponse
    {
        $companies = Company::query()->accessibleTo($request->user());
        $company = (clone $companies)->find($request->session()->get('navigation.company'))
            ?? $companies->orderBy('name')->orderBy('id')->first();
        if (! $company) {
            return Inertia::render('companies/empty');
        }
        $sites = $company->sites()->orderBy('id');
        $site = (clone $sites)->find($request->session()->get('navigation.site')) ?? $sites->firstOrFail();

        return to_route('companies.sites.subnets.index', [$company, $site]);
    }

    public function store(CompanyRequest $request): RedirectResponse
    {
        $inline = $request->validate(['inline' => ['sometimes', 'boolean']])['inline'] ?? false;
        $company = DB::transaction(function () use ($request) {
            $company = Company::create($request->validated());
            $company->sites()->create(['name' => null]);

            return $company;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Azienda creata.']);

        if ($inline) {
            Inertia::flash('createdCompany', [...$company->only('id', 'name'), 'site_id' => $company->sites()->sole()->id]);

            return back();
        }

        return to_route('companies.show', $company);
    }

    public function show(Request $request, Company $company, ?Site $site = null): Response|RedirectResponse
    {
        $sites = $company->sites()->orderBy('id')->get(['id', 'name']);

        if (! $request->routeIs('companies.settings.*')) {
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
            DeletionImpact::deleteDevices(array_column($impact['devices'], 'id'));
            DB::table('vlans')->whereIn('site_id', array_column($impact['sites'], 'id'))->delete();
            $company->sites()->delete();
            $company->delete();
        }, 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Azienda eliminata.']);

        // The workspace entry point picks the next company, or shows the empty state.
        return to_route('companies.index');
    }
}

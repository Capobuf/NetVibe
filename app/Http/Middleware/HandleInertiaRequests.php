<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $area = $request->routeIs('administration.*') ? 'administration'
            : ($request->routeIs('companies.*', 'manufacturers.*', 'device-models.index', 'model-types.*') ? 'tenant' : $request->session()->get('navigation.area', 'tenant'));
        $company = $area === 'tenant' ? $request->route('company') : null;
        $company ??= Company::query()->find($request->session()->get('navigation.company'));
        $canAccessCompany = $company instanceof Company && $request->user()?->can('access-company', $company);
        $sites = $canAccessCompany ? $company->sites()->orderBy('id')->get(['id', 'name']) : collect();
        $site = $area === 'tenant' && $request->route('site') instanceof Site ? $request->route('site') : null;
        $site ??= $sites->firstWhere('id', $request->session()->get('navigation.site')) ?? $sites->first();

        // Prefetching a link must not change the user's active workspace.
        if ($request->isMethod('GET') && $request->header('Purpose') !== 'prefetch') {
            $request->session()->put('navigation.area', $area);
            if ($canAccessCompany && $site) {
                $request->session()->put(['navigation.company' => $company->id, 'navigation.site' => $site->id]);
            }
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'navigationArea' => $area,
            'auth' => [
                'user' => $request->user(),
                'canEditIpam' => $area === 'tenant' && $canAccessCompany,
            ],
            'companyNavigation' => fn () => $request->user()?->hasVerifiedEmail() ? [
                'companies' => Company::query()->accessibleTo($request->user())->orderBy('name')->orderBy('id')->get(['id', 'name']),
                'currentCompanyId' => $canAccessCompany ? $company->id : null,
                'sites' => $sites,
                'currentSiteId' => $canAccessCompany && $site !== null
                    ? $site->id
                    : null,
            ] : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}

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
        $company = $request->route('company');
        $canAccessCompany = $company instanceof Company && $request->user()?->can('access-company', $company);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'canEditIpam' => $canAccessCompany,
            ],
            'companyNavigation' => fn () => $request->user()?->hasVerifiedEmail() ? [
                'companies' => Company::query()->accessibleTo($request->user())->orderBy('name')->orderBy('id')->get(['id', 'name']),
                'currentCompanyId' => $canAccessCompany ? $company->id : null,
                'sites' => $canAccessCompany
                    ? $company->sites()->orderBy('id')->get(['id', 'name'])
                    : [],
                'currentSiteId' => $canAccessCompany && $request->route('site') instanceof Site
                    ? $request->route('site')->id
                    : null,
            ] : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}

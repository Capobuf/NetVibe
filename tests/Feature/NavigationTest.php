<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_preserves_the_selected_tenant_and_changes_area_only_on_a_visit(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $company->sites()->create(['name' => 'Torino']);
        $selected = $company->sites()->create(['name' => 'Roma']);
        $other = Company::create(['name' => 'Beta']);
        $other->sites()->create(['name' => null]);

        $this->get(route('companies.sites.subnets.index', [$company, $selected]))->assertOk();
        foreach ([route('companies.settings.show', $company), route('device-models.index'), route('profile.edit')] as $url) {
            $this->get($url)->assertInertia(fn (Assert $page) => $page
                ->where('navigationArea', 'tenant')
                ->where('companyNavigation.currentCompanyId', $company->id)
                ->where('companyNavigation.currentSiteId', $selected->id));
        }
        $this->get(route('administration.companies.show', $other), ['Purpose' => 'prefetch'])->assertOk();
        $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('navigationArea', 'tenant'));
        $this->get(route('administration.companies.show', $other))->assertInertia(fn (Assert $page) => $page
            ->where('navigationArea', 'administration')
            ->where('companyNavigation.currentCompanyId', $company->id));
        $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('navigationArea', 'administration'));
        $this->get(route('companies.settings.show', $company))->assertInertia(fn (Assert $page) => $page
            ->where('navigationArea', 'tenant')
            ->where('companyNavigation.currentSiteId', $selected->id));
    }
}

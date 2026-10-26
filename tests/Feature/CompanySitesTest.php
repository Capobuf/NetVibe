<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanySitesTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_search_and_sort_apply_before_pagination(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        for ($i = 0; $i < 21; $i++) {
            $company = Company::create(['name' => sprintf('Match %02d', $i)]);
            $company->sites()->create(['name' => null]);
        }
        $company->sites()->create(['name' => 'Roma']);
        $excluded = Company::create(['name' => 'Excluded']);
        $excluded->sites()->create(['name' => null]);

        $this->get(route('administration.companies.index', [
            'search' => 'Match', 'sort' => 'sites_count', 'direction' => 'desc',
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('companies/index')
            ->where('companies.total', 21)
            ->has('companies.data', 20)
            ->where('companies.data.0.id', $company->id)
            ->where('companies.data.0.sites_count', 2)
            ->where('companies.next_page_url', fn (string $url) => str_contains($url, 'search=Match')
                && str_contains($url, 'sort=sites_count') && str_contains($url, 'direction=desc'))
        );

        $this->get(route('administration.companies.index', [
            'search' => 'Match 20',
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('companies.total', 1)->where('companies.data.0.id', $company->id)
        );

        $this->get(route('administration.companies.index', [
            'search' => 'Match %',
        ]))->assertInertia(fn (Assert $page) => $page->where('companies.total', 0));
    }

    public function test_adding_a_second_site_preserves_the_original_site_and_company_boundaries(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->post(route('administration.companies.store'), ['name' => 'Alfa'])->assertRedirect();
        $company = Company::where('name', 'Alfa')->sole();
        $originalSite = $company->sites()->sole();
        $otherCompany = Company::create(['name' => 'Beta']);
        $otherSite = $otherCompany->sites()->create(['name' => 'Milano']);

        $this->post(route('administration.companies.sites.store', $company), [
            'name' => 'Roma',
        ])->assertSessionHasErrors('existing_site_name');
        $this->assertDatabaseCount('sites', 2);

        $this->post(route('administration.companies.sites.store', $company), [
            'existing_site_name' => 'Torino',
            'name' => 'Roma',
        ])->assertRedirect();

        $this->assertDatabaseCount('sites', 3);
        $this->assertDatabaseHas('sites', [
            'id' => $originalSite->id,
            'company_id' => $company->id,
            'name' => 'Torino',
        ]);
        $this->assertDatabaseHas('sites', ['company_id' => $company->id, 'name' => 'Roma']);

        $this->get(route('companies.sites.show', [$company, $otherSite]))->assertNotFound();
        $this->patch(route('administration.companies.sites.update', [$company, $otherSite]), [
            'name' => 'Changed',
        ])->assertNotFound();
        $this->assertDatabaseHas('sites', [
            'id' => $otherSite->id,
            'company_id' => $otherCompany->id,
            'name' => 'Milano',
        ]);
    }

    public function test_non_superadmins_cannot_access_administration_or_delete_companies(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $this->actingAs(User::factory()->create(['is_superadmin' => false]));

        $this->get(route('administration.companies.index'))->assertForbidden();
        $this->delete(route('administration.companies.destroy', $company), [
            'confirmed' => true,
            'site_ids' => [$site->id],
        ])->assertForbidden();

        $this->assertModelExists($company);
        $this->assertModelExists($site);
    }

    public function test_company_deletion_requires_confirmation_and_an_unchanged_impact(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $first = $company->sites()->create(['name' => 'Torino']);
        $second = $company->sites()->create(['name' => 'Roma']);
        $otherCompany = Company::create(['name' => 'Beta']);
        $otherSite = $otherCompany->sites()->create(['name' => null]);
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));

        $this->delete(route('administration.companies.destroy', $company), [
            'site_ids' => [$first->id, $second->id],
        ])->assertSessionHasErrors('confirmed');
        $this->delete(route('administration.companies.destroy', $company), [
            'confirmed' => true,
            'site_ids' => [$first->id],
        ])->assertSessionHasErrors('confirmed');
        $this->assertModelExists($company);
        $this->assertDatabaseCount('sites', 3);

        $this->delete(route('administration.companies.destroy', $company), [
            'confirmed' => true,
            'site_ids' => [$first->id, $second->id],
        ])->assertRedirect(route('administration.companies.index'));

        $this->assertModelMissing($company);
        $this->assertModelMissing($first);
        $this->assertModelMissing($second);
        $this->assertModelExists($otherCompany);
        $this->assertModelExists($otherSite);
    }

    public function test_site_deletion_preserves_the_remaining_site_and_cannot_remove_the_last_one(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $first = $company->sites()->create(['name' => 'Torino']);
        $second = $company->sites()->create(['name' => 'Roma']);
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));

        $this->delete(route('administration.companies.sites.destroy', [$company, $second]), [
            'confirmed' => true,
            'site_ids' => [$first->id, $second->id],
        ])->assertRedirect(route('administration.companies.show', $company));

        $this->assertModelMissing($second);
        $this->assertModelExists($first);

        $this->delete(route('administration.companies.sites.destroy', [$company, $first]), [
            'confirmed' => true,
            'site_ids' => [$first->id],
        ])->assertSessionHasErrors('confirmed');

        $this->assertDatabaseCount('sites', 1);
        $this->assertModelExists($first);
        $this->assertModelExists($company);
    }
}

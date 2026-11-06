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

    public function test_administration_opens_the_users_tab(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));

        $this->get(route('administration.index'))->assertRedirect('/administration/users');
    }

    public function test_inline_company_creation_returns_to_unifi_with_its_local_site_without_linking_it(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $returnUrl = route('administration.integrations.unifi.index');

        $response = $this->from($returnUrl)->post(route('administration.companies.store'), [
            'name' => 'Nuova azienda UniFi', 'inline' => true,
        ]);

        $company = Company::where('name', 'Nuova azienda UniFi')->sole();
        $site = $company->sites()->sole();
        $response->assertSessionHasNoErrors()->assertRedirect($returnUrl)
            ->assertInertiaFlash('createdCompany.id', $company->id)
            ->assertInertiaFlash('createdCompany.name', 'Nuova azienda UniFi')
            ->assertInertiaFlash('createdCompany.site_id', $site->id);
        $this->assertNull($site->name);
        $this->assertDatabaseCount('unifi_sites', 0);
    }

    public function test_entry_point_opens_the_remembered_accessible_company_or_the_empty_state(): void
    {
        $beta = Company::create(['name' => 'Beta']);
        $betaSite = $beta->sites()->create(['name' => null]);
        $alfa = Company::create(['name' => 'Alfa']);
        $alfaFirst = $alfa->sites()->create(['name' => 'Torino']);
        $alfaSecond = $alfa->sites()->create(['name' => 'Roma']);
        $foreign = Company::create(['name' => 'Aaa']);
        $foreignSite = $foreign->sites()->create(['name' => null]);
        $user = User::factory()->create(['is_superadmin' => false]);
        $user->companies()->attach([$alfa->id, $beta->id]);
        $this->actingAs($user);

        $this->get(route('companies.index'))->assertRedirect(route('companies.sites.subnets.index', [$alfa, $alfaFirst]));
        $this->withSession(['navigation.company' => $beta->id, 'navigation.site' => $alfaSecond->id])
            ->get(route('companies.index'))->assertRedirect(route('companies.sites.subnets.index', [$beta, $betaSite]));
        $this->withSession(['navigation.company' => $alfa->id, 'navigation.site' => $alfaSecond->id])
            ->get(route('companies.index'))->assertRedirect(route('companies.sites.subnets.index', [$alfa, $alfaSecond]));
        // A remembered company the user cannot access is never opened.
        $this->withSession(['navigation.company' => $foreign->id, 'navigation.site' => $foreignSite->id])
            ->get(route('companies.index'))->assertRedirect(route('companies.sites.subnets.index', [$alfa, $alfaFirst]));

        $user->companies()->detach();
        $this->get(route('companies.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('companies/empty'));
    }

    public function test_adding_a_second_site_preserves_the_original_site_and_company_boundaries(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->post(route('administration.companies.store'), ['name' => 'Alfa'])->assertRedirect();
        $company = Company::where('name', 'Alfa')->sole();
        $originalSite = $company->sites()->sole();
        $otherCompany = Company::create(['name' => 'Beta']);
        $otherSite = $otherCompany->sites()->create(['name' => 'Milano']);

        $this->post(route('companies.sites.store', $company), [
            'name' => 'Roma',
        ])->assertSessionHasErrors('existing_site_name');
        $this->assertDatabaseCount('sites', 2);

        $this->post(route('companies.sites.store', $company), [
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
        $this->patch(route('companies.sites.update', [$company, $otherSite]), [
            'name' => 'Changed',
        ])->assertNotFound();
        $this->assertDatabaseHas('sites', [
            'id' => $otherSite->id,
            'company_id' => $otherCompany->id,
            'name' => 'Milano',
        ]);
    }

    public function test_unassigned_users_cannot_access_company_data(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $this->actingAs(User::factory()->create(['is_superadmin' => false]));

        $this->get(route('profile.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')->has('companyNavigation.companies', 0));
        $this->get(route('appearance.edit'))->assertOk();
        $this->get(route('companies.index'))->assertInertia(fn (Assert $page) => $page->component('companies/empty'));
        $this->get(route('companies.show', $company))->assertForbidden();
        $this->get(route('companies.sites.show', [$company, $site]))->assertForbidden();
        $this->get(route('administration.index'))->assertForbidden();
        $this->delete(route('administration.companies.destroy', $company), [
            'confirmed' => true,
            'impact_token' => str_repeat('0', 64),
        ])->assertForbidden();

        $this->assertModelExists($company);
        $this->assertModelExists($site);
    }

    public function test_company_deletion_requires_confirmation_and_an_unchanged_impact(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $first = $company->sites()->create(['name' => 'Torino']);
        $otherCompany = Company::create(['name' => 'Beta']);
        $otherSite = $otherCompany->sites()->create(['name' => null]);
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));

        $token = $this->getJson(route('administration.companies.deletion-impact', $company))->assertOk()->json('impact_token');
        $second = $company->sites()->create(['name' => 'Roma']);

        $this->delete(route('administration.companies.destroy', $company), [
            'impact_token' => $token,
        ])->assertSessionHasErrors('confirmed');
        $this->delete(route('administration.companies.destroy', $company), [
            'confirmed' => true,
            'impact_token' => $token,
        ])->assertSessionHasErrors('impact_token');
        $this->assertModelExists($company);
        $this->assertDatabaseCount('sites', 3);
        $token = $this->getJson(route('administration.companies.deletion-impact', $company))->assertOk()->json('impact_token');

        $this->delete(route('administration.companies.destroy', $company), [
            'confirmed' => true,
            'impact_token' => $token,
        ])->assertRedirect(route('companies.index'));

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

        $token = $this->getJson(route('companies.sites.deletion-impact', [$company, $second]))->assertOk()->json('impact_token');
        $this->delete(route('companies.sites.destroy', [$company, $second]), [
            'confirmed' => true,
            'impact_token' => $token,
        ])->assertRedirect(route('companies.settings.show', $company));

        $this->assertModelMissing($second);
        $this->assertModelExists($first);

        $token = $this->getJson(route('companies.sites.deletion-impact', [$company, $first]))->assertOk()->json('impact_token');
        $this->delete(route('companies.sites.destroy', [$company, $first]), [
            'confirmed' => true,
            'impact_token' => $token,
        ])->assertSessionHasErrors('confirmed');

        $this->assertDatabaseCount('sites', 1);
        $this->assertModelExists($first);
        $this->assertModelExists($company);
    }
}

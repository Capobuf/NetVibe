<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SiteNetworksTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlaps_are_rejected_across_vlans_but_not_across_sites(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Torino']);
        $other = $company->sites()->create(['name' => 'Roma']);
        $store = route('companies.sites.subnets.store', [$company, $site]);

        $this->post($store, ['cidr' => '192.168.1.42/24'])->assertRedirect();
        $subnet = $site->subnets()->sole();
        $this->assertSame('192.168.1.0/24', $subnet->cidr);
        $this->assertNull($subnet->vlan_id);

        $this->post($store, ['cidr' => '192.168.1.128/25', 'new_vlan' => ['tag' => 10]])
            ->assertSessionHasErrors('cidr');
        $this->assertDatabaseCount('vlans', 0);
        $this->assertDatabaseCount('subnets', 1);

        $this->post($store, ['cidr' => '192.168.2.0/24', 'new_vlan' => ['tag' => 10]])->assertRedirect();
        $this->patch(route('companies.sites.subnets.update', [$company, $site, $subnet]), [
            'cidr' => '192.168.2.128/25',
        ])->assertSessionHasErrors('cidr');
        $this->assertSame('192.168.1.0/24', $subnet->fresh()->cidr);

        $vlan = $site->vlans()->sole();
        $this->post($store, ['cidr' => '192.168.3.0/24', 'vlan_id' => $vlan->id])->assertRedirect();
        $this->assertDatabaseHas('subnets', ['site_id' => $site->id, 'cidr' => '192.168.3.0/24', 'vlan_id' => $vlan->id]);
        $this->patch(route('companies.sites.subnets.update', [$company, $site, $subnet]), [
            'cidr' => $subnet->cidr, 'vlan_id' => $vlan->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('subnets', ['id' => $subnet->id, 'vlan_id' => $vlan->id]);
        $this->post($store, [
            'cidr' => '192.168.4.0/24', 'vlan_id' => $vlan->id, 'new_vlan' => ['tag' => 20],
        ])->assertSessionHasErrors('vlan_id');
        $this->assertDatabaseCount('vlans', 1);
        $this->assertDatabaseCount('subnets', 3);

        $this->post(route('companies.sites.subnets.store', [$company, $other]), [
            'cidr' => '192.168.1.0/24', 'new_vlan' => ['tag' => 10],
        ])->assertRedirect();
        $this->assertDatabaseCount('subnets', 4);
        $this->assertDatabaseCount('vlans', 2);
    }

    public function test_vlan_uniqueness_context_and_container_deletion_protect_network_data(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $otherCompany = Company::create(['name' => 'Beta']);
        $otherSite = $otherCompany->sites()->create(['name' => null]);
        $vlan = $site->vlans()->create(['tag' => 10]);

        $this->get(route('companies.show', $company))
            ->assertRedirect(route('companies.sites.subnets.index', [$company, $site]));
        $this->get(route('companies.sites.vlans.index', [$company, $site]))
            ->assertInertia(fn (Assert $page) => $page->component('vlans/index')
                ->has('vlans', 1)->where('vlans.0.id', $vlan->id)
                ->where('companyNavigation.currentSiteId', $site->id));
        $this->get(route('companies.sites.subnets.index', [$company, $otherSite]))->assertNotFound();
        $this->get(route('companies.sites.vlans.index', [$company, $otherSite]))->assertNotFound();

        $this->post(route('companies.sites.vlans.store', [$company, $site]), ['tag' => 10])
            ->assertSessionHasErrors('tag');
        $this->assertDatabaseCount('vlans', 1);
        $this->post(route('companies.sites.subnets.store', [$otherCompany, $otherSite]), [
            'cidr' => '10.0.0.0/24', 'vlan_id' => $vlan->id,
        ])->assertSessionHasErrors('vlan_id');
        $this->post(route('companies.sites.subnets.store', [$company, $otherSite]), [
            'cidr' => '10.0.0.0/24',
        ])->assertNotFound();
        $this->assertDatabaseCount('subnets', 0);

        $this->delete(route('administration.companies.destroy', $company), [
            'confirmed' => true, 'site_ids' => [$site->id],
        ])->assertSessionHasErrors('confirmed');
        $this->assertModelExists($company);
        $this->assertModelExists($site);
        $this->assertModelExists($vlan);

        $this->actingAs(User::factory()->create(['is_superadmin' => false]));
        $this->post(route('companies.sites.subnets.store', [$company, $site]), [
            'cidr' => '10.0.0.0/24',
        ])->assertForbidden();
        $this->assertDatabaseCount('subnets', 0);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\Ipv4Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SiteNetworksTest extends TestCase
{
    use RefreshDatabase;

    public function test_vlan_detail_lists_only_its_subnets_and_rejects_mismatched_contexts(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Torino']);
        $otherSite = $company->sites()->create(['name' => 'Roma']);
        $otherCompany = Company::create(['name' => 'Beta']);
        $vlan = $site->vlans()->create(['tag' => 10, 'name' => 'Uffici']);
        $emptyVlan = $site->vlans()->create(['tag' => 20]);
        $neighbourVlan = $site->vlans()->create(['tag' => 30]);
        $otherVlan = $otherSite->vlans()->create(['tag' => 10]);
        $subnet = $site->subnets()->create([
            ...Ipv4Cidr::parse('192.168.1.0/24'),
            'vlan_id' => $vlan->id, 'name' => 'LAN', 'description' => 'Postazioni ufficio',
        ]);
        $site->subnets()->create(Ipv4Cidr::parse('192.168.2.0/24'));
        $site->subnets()->create([
            ...Ipv4Cidr::parse('192.168.3.0/24'), 'vlan_id' => $neighbourVlan->id,
        ]);
        $otherSite->subnets()->create([
            ...Ipv4Cidr::parse('192.168.1.0/24'), 'vlan_id' => $otherVlan->id,
        ]);
        $url = route('companies.sites.vlans.show', [$company, $site, $vlan]);

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create());
        $this->get($url)->assertRedirect(route('verification.notice'));

        $this->actingAs(User::factory()->create(['is_superadmin' => false]));
        $this->get($url)->assertForbidden();
        $this->get(route('companies.sites.vlans.index', [$company, $site]))->assertForbidden();
        $this->get(route('companies.sites.subnets.index', [$company, $site]))->assertForbidden();
        $this->get(route('companies.sites.subnets.show', [$company, $site, $subnet]))->assertForbidden();

        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->get(route('companies.sites.vlans.index', [$company, $site]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('vlans/index')
                ->has('vlans', 3)->where('vlans.0.id', $vlan->id)
                ->where('vlans.0.subnets_count', 1)->has('vlans.0.subnets', 1)
                ->where('vlans.0.subnets.0.id', $subnet->id)
                ->where('vlans.0.subnets.0.cidr', '192.168.1.0/24')
                ->where('vlans.0.subnets.0.name', 'LAN')
                ->where('vlans.0.subnets.0.description', 'Postazioni ufficio')
                ->where('vlans.1.id', $emptyVlan->id)->has('vlans.1.subnets', 0)
                ->where('vlans.2.id', $neighbourVlan->id)->has('vlans.2.subnets', 1)
                ->where('vlans.2.subnets.0.cidr', '192.168.3.0/24'));
        $this->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('vlans/show')
                ->where('company.id', $company->id)->where('site.id', $site->id)
                ->where('vlan.id', $vlan->id)->where('vlan.tag', 10)->where('vlan.name', 'Uffici')
                ->has('subnets', 1)->where('subnets.0.id', $subnet->id)
                ->where('subnets.0.cidr', '192.168.1.0/24')
                ->where('subnets.0.name', 'LAN')->where('subnets.0.description', 'Postazioni ufficio')
                ->where('companyNavigation.currentCompanyId', $company->id)
                ->where('companyNavigation.currentSiteId', $site->id));
        $this->get(route('companies.sites.subnets.show', [$company, $site, $subnet]))->assertOk();
        $this->get(route('companies.sites.vlans.show', [$company, $site, $emptyVlan]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('vlans/show')
            ->where('vlan.name', null)->has('subnets', 0));
        $this->get(route('companies.sites.vlans.show', [$company, $site, $otherVlan]))->assertNotFound();
        $this->get(route('companies.sites.vlans.show', [$otherCompany, $site, $vlan]))->assertNotFound();
    }

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

    public function test_vlan_uniqueness_context_and_deletion_without_preview_protect_network_data(): void
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
            'confirmed' => true,
        ])->assertSessionHasErrors('impact_token');
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

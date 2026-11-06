<?php

namespace Tests\Feature;

use App\Models\Cable;
use App\Models\Company;
use App\Models\Manufacturer;
use App\Models\User;
use App\Support\Ipv4Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IpSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_covers_company_sites_without_leaving_the_context_or_exposing_other_companies(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Milano']);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        foreach (range(1, 20) as $last) {
            $subnet->ipAddresses()->create(['address' => ip2long("10.0.0.$last"), 'hostname' => "node-$last"]);
        }
        $ip = $subnet->ipAddresses()->create([
            'address' => ip2long('10.0.0.200'), 'hostname' => 'node-target', 'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
        $secondSite = $company->sites()->create(['name' => 'Roma']);
        $secondSubnet = $secondSite->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $secondIp = $secondSubnet->ipAddresses()->create([
            'address' => ip2long('10.0.0.200'), 'description' => 'Stampante senza hostname e MAC',
        ]);
        $otherCompany = Company::create(['name' => 'Beta']);
        $otherSite = $otherCompany->sites()->create(['name' => 'Roma']);
        $otherSubnet = $otherSite->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $otherIp = $otherSubnet->ipAddresses()->create([
            'address' => ip2long('10.0.0.200'), 'hostname' => 'foreign-node', 'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
        $search = route('companies.sites.search.index', [$company, $site]);
        $this->get($search)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create());
        $this->get($search)->assertRedirect(route('verification.notice'));
        $this->actingAs(User::factory()->create(['is_superadmin' => false]));
        $this->get($search.'?search=10.0.0.200')->assertForbidden();
        $this->get('/search?search=10.0.0.200')->assertRedirect('/companies');
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));

        $this->get($search.'?search=10.0.0.200')->assertInertia(fn (Assert $page) => $page
            ->component('search/index')->where('results.total', 2)
            ->where('companyNavigation.currentCompanyId', $company->id)
            ->where('companyNavigation.currentSiteId', $site->id)->has('companyNavigation.sites', 2)
            ->where('results.data.0.id', $ip->id)->where('results.data.0.site_id', $site->id)
            ->where('results.data.1.id', $secondIp->id)->where('results.data.1.site_id', $secondSite->id)
            ->where('results.data.1.hostname', null)->where('results.data.1.mac_address', null));
        $this->get($search.'?search=node')->assertInertia(fn (Assert $page) => $page
            ->where('results.total', 21)->has('results.data', 20));
        $this->get($search.'?search=aa:bb:cc')->assertInertia(fn (Assert $page) => $page
            ->where('results.total', 1)->where('results.data.0.id', $ip->id));
        $this->get($search.'?search=foreign')->assertInertia(fn (Assert $page) => $page->where('results.total', 0));
        $this->get($search.'?search=10.0.0.&sort=ip&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('results.total', 22)->where('results.data.0.ip', '10.0.0.200')
            ->where('results.data.2.ip', '10.0.0.20')
            ->where('results.next_page_url', fn (string $url) => str_starts_with($url, $search.'?')
                && str_contains($url, 'search=10.0.0.') && str_contains($url, 'sort=ip')
                && str_contains($url, 'direction=desc') && str_contains($url, 'page=2')));
        $this->get($search)->assertInertia(fn (Assert $page) => $page->where('results.total', 0));
        $this->get($search.'?search=%25')->assertInertia(fn (Assert $page) => $page->where('results.total', 0));
        $this->get($search.'?search=10.0.0.&group=site&sort=ip&direction=desc&page=2')->assertInertia(fn (Assert $page) => $page
            ->where('query.group', 'site')->where('results.total', 22)->has('results.data', 2)
            ->where('results.data.0.site_id', $site->id)->where('results.data.0.ip', '10.0.0.1')
            ->where('results.data.1.id', $secondIp->id)
            ->where('results.prev_page_url', fn (string $url) => str_starts_with($url, $search.'?') && str_contains($url, 'group=site')));
        $this->get(route('companies.sites.search.index', [$company, $secondSite]).'?search=node')->assertInertia(fn (Assert $page) => $page
            ->where('results.total', 21)->where('companyNavigation.currentSiteId', $secondSite->id));
        $this->get(route('companies.sites.search.index', [$company, $otherSite]))->assertNotFound();
        $this->get('/search?search=10.0.0.200')->assertRedirect('/companies');

        $show = route('companies.sites.subnets.show', [$company, $site, $subnet]);
        $this->get($show.'?ip_address='.$ip->id)->assertInertia(fn (Assert $page) => $page
            ->where('initialIpAddress.id', $ip->id)->where('initialIpAddress.ip', '10.0.0.200')
            ->where('company.id', $company->id)->where('site.id', $site->id)->where('subnet.id', $subnet->id));
        $this->get($show.'?ip_address='.$otherIp->id)->assertInertia(fn (Assert $page) => $page
            ->where('initialIpAddress', null));
        $this->get(route('companies.sites.subnets.show', [$company, $site, $otherSubnet]))->assertNotFound();
    }

    public function test_suggestions_and_results_list_company_inventory_and_cabling_under_their_network(): void
    {
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $model = $vendor->models()->create(['name' => 'Edge Switch']);
        $sites = collect(['Alfa', 'Beta'])->map(function (string $name) use ($vendor, $model) {
            $site = Company::create(['name' => $name])->sites()->create(['name' => 'Sede']);
            $vlan = $site->vlans()->create(['tag' => 30, 'name' => 'Server']);
            $subnet = $site->subnets()->create([...Ipv4Cidr::parse('10.0.30.0/24'), 'vlan_id' => $vlan->id]);
            $subnet->ipAddresses()->create(['address' => ip2long('10.0.30.5'), 'hostname' => "edge-host-{$name}"]);
            $devices = collect(['a', 'b'])->map(fn ($end) => $site->devices()->create([
                'name' => "edge-{$name}-{$end}", 'manufacturer_id' => $vendor->id, 'device_model_id' => $model->id,
            ]));
            $rack = $site->racks()->create(['name' => "edge-rack-{$name}", 'height_u' => 24, 'numbering' => 'bottom-up']);
            $rack->items()->create(['key' => str_repeat('d', 32), 'kind' => 'device', 'device_id' => $devices[0]->id, 'position' => 0, 'height' => 1, 'width' => 100, 'x' => 0]);
            $cable = Cable::create(['site_id' => $site->id, 'type' => 'copper', 'color' => '#2563eb']);
            $cable->ends()->createMany([
                ['side' => 'a', 'device_id' => $devices[0]->id, 'connector_id' => str_repeat('a', 32)],
                ['side' => 'b', 'device_id' => $devices[1]->id, 'connector_id' => str_repeat('a', 32)],
            ]);

            return compact('site', 'vlan', 'subnet', 'rack', 'cable');
        });
        ['site' => $site, 'vlan' => $vlan, 'subnet' => $subnet, 'rack' => $rack, 'cable' => $cable] = $sites[0];
        $company = $site->company_id;
        $suggestions = route('companies.sites.search.suggestions', [$company, $site]).'?search=edge';
        $this->actingAs(User::factory()->create(['is_superadmin' => false]));
        $this->getJson($suggestions)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));

        $sections = collect($this->getJson($suggestions)->assertOk()->json())->keyBy('label');
        $this->assertSame(['Rete', 'Dispositivi', 'Modelli', 'Rack', 'Cablaggi'], $sections->keys()->all());
        $this->assertSame([['vlan', 0], ['subnet', 1], ['ip', 2]], collect($sections['Rete']['rows'])->map(fn ($row) => [$row['kind'], $row['depth']])->all());
        $this->assertSame('edge-host-Alfa', $sections['Rete']['rows'][2]['detail']);
        $this->assertSame(['edge-Alfa-a', 'edge-Alfa-b'], array_column($sections['Dispositivi']['rows'], 'label'));
        $this->assertSame(['edge-rack-Alfa'], array_column($sections['Rack']['rows'], 'label'));
        $this->assertSame(route('companies.sites.racks.show', [$company, $site, $rack, 'cable' => $cable->id], false), $sections['Cablaggi']['rows'][0]['href']);

        $this->get(route('companies.sites.search.index', [$company, $site]).'?search=edge&tab=cables')->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'cables')->where('results.total', 1)->has('devices', 2)->has('models', 1)->where('models.0.devices_count', 2)
            ->has('racks', 1)->where('racks.0.devices_count', 1)->has('cables', 1)->where('cables.0.a', 'edge-Alfa-a@aaaaaaaa'));
        $this->get(route('companies.sites.racks.show', [$company, $site, $rack]).'?cable='.$cable->id)
            ->assertInertia(fn (Assert $page) => $page->where('selectedCable', $cable->id));
        $this->getJson(route('companies.sites.search.suggestions', [$company, $sites[1]['site']]).'?search=edge')->assertNotFound();
    }
}

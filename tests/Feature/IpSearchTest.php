<?php

namespace Tests\Feature;

use App\Models\Company;
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
}

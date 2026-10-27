<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\Ipv4Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IpAddressesTest extends TestCase
{
    use RefreshDatabase;

    public function test_ip_table_search_and_numeric_sort_cover_all_pages_and_stay_within_the_subnet(): void
    {
        $this->actingAs(User::factory()->create());
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        foreach (range(2, 52) as $last) {
            $subnet->ipAddresses()->create(['address' => ip2long("10.0.0.$last"), 'hostname' => "node-$last"]);
        }
        $subnet->ipAddresses()->create([
            'address' => ip2long('10.0.0.200'), 'hostname' => 'target',
            'description' => 'Carico 50%', 'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
        $otherSubnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.1.0/24'));
        $otherSubnet->ipAddresses()->create(['address' => ip2long('10.0.1.200'), 'hostname' => 'target']);
        $show = route('companies.sites.subnets.show', [$company, $site, $subnet]);

        $this->get($show)->assertInertia(fn (Assert $page) => $page
            ->where('query.sort', null)->where('query.direction', 'asc')
            ->where('ipAddresses.total', 52)->where('ipAddresses.data.0.ip', '10.0.0.2')
            ->where('ipAddresses.data.1.ip', '10.0.0.3'));
        $this->get($show.'?search=target')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 1)->where('ipAddresses.data.0.ip', '10.0.0.200'));
        $this->get($show.'?search=%25')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 1)->where('ipAddresses.data.0.hostname', 'target'));
        $this->get($show.'?search=node&sort=&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('query.sort', null)->where('query.direction', 'asc')
            ->where('ipAddresses.total', 51)->where('ipAddresses.data.0.ip', '10.0.0.2'));
        $this->get($show.'?search=10.0.0.&sort=ip&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.data.0.ip', '10.0.0.200')
            ->where('ipAddresses.data.1.ip', '10.0.0.52')
            ->where('ipAddresses.next_page_url', function ($url) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query === ['search' => '10.0.0.', 'sort' => 'ip', 'direction' => 'desc', 'page' => '2'];
            }));
    }

    public function test_documented_ip_is_unique_and_keeps_its_data_when_moved_until_explicitly_freed(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('192.168.1.0/24'));
        $show = route('companies.sites.subnets.show', [$company, $site, $subnet]);
        $store = route('companies.sites.subnets.ip-addresses.store', [$company, $site, $subnet]);

        $this->from($show)->post($store, ['ip' => '192.168.1.10', 'description' => 'Stampante'])
            ->assertRedirect($show)->assertSessionHasNoErrors();
        $ip = $subnet->ipAddresses()->sole();
        $this->from($show)->post($store, ['ip' => '192.168.1.10', 'hostname' => 'duplicato'])
            ->assertSessionHasErrors('ip');
        $this->assertDatabaseCount('ip_addresses', 1);
        $this->assertDatabaseHas('ip_addresses', [
            'id' => $ip->id, 'address' => 3232235786, 'description' => 'Stampante', 'hostname' => null, 'mac_address' => null,
        ]);

        $update = route('companies.sites.subnets.ip-addresses.update', [$company, $site, $subnet, $ip]);
        $this->patch($update, ['ip' => '192.168.1.20', 'description' => 'Stampante', 'mac_address' => 'AA:BB:CC:DD:EE:FF'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ip_addresses', [
            'id' => $ip->id, 'address' => 3232235796, 'description' => 'Stampante', 'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
        $this->post($store, ['ip' => '192.168.1.10', 'hostname' => 'printer', 'mac_address' => 'AA:BB:CC:DD:EE:FF'])
            ->assertSessionHasNoErrors();
        $this->patch($update, ['ip' => '192.168.1.10', 'description' => 'Stampante'])
            ->assertSessionHasErrors('ip');
        $this->assertDatabaseHas('ip_addresses', ['id' => $ip->id, 'address' => 3232235796]);

        $this->get($show)->assertInertia(fn (Assert $page) => $page->component('subnets/show')
            ->where('ipAddresses.total', 2)->where('ipAddresses.data.0.ip', '192.168.1.10')
            ->where('ipAddresses.data.1.ip', '192.168.1.20'));

        $delete = route('companies.sites.subnets.ip-addresses.destroy', [$company, $site, $subnet, $ip]);
        $this->delete($delete)->assertSessionHasErrors('confirmed');
        $this->assertModelExists($ip);
        $this->delete($delete, ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertModelMissing($ip);
        $this->assertDatabaseCount('ip_addresses', 1);
    }

    public function test_ip_writes_respect_subnet_boundaries_context_and_permissions(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('192.168.1.0/24'));
        $otherCompany = Company::create(['name' => 'Beta']);
        $otherSite = $otherCompany->sites()->create(['name' => null]);
        $otherSubnet = $otherSite->subnets()->create(Ipv4Cidr::parse('192.168.1.0/24'));
        $store = route('companies.sites.subnets.ip-addresses.store', [$company, $site, $subnet]);

        $this->post($store, ['ip' => '192.168.1.200'])->assertSessionHasErrors('description');
        $this->post($store, ['ip' => '192.168.2.200', 'hostname' => 'server'])->assertSessionHasErrors('ip');
        $this->assertDatabaseCount('ip_addresses', 0);
        $this->post($store, ['ip' => '192.168.1.200', 'hostname' => 'server'])->assertSessionHasNoErrors();
        $ip = $subnet->ipAddresses()->sole();
        $this->post(route('companies.sites.subnets.ip-addresses.store', [$otherCompany, $otherSite, $otherSubnet]), [
            'ip' => '192.168.1.200', 'description' => 'Altro cliente',
        ])->assertSessionHasNoErrors();

        $this->patch(route('companies.sites.subnets.update', [$company, $site, $subnet]), ['cidr' => '192.168.1.0/25'])
            ->assertSessionHasErrors('cidr');
        $this->assertDatabaseHas('subnets', ['id' => $subnet->id, 'cidr' => '192.168.1.0/24']);
        $this->patch(route('companies.sites.subnets.update', [$company, $site, $subnet]), ['cidr' => '192.168.1.128/25'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subnets', ['id' => $subnet->id, 'cidr' => '192.168.1.128/25']);
        $this->post($store, ['ip' => '192.168.1.10', 'description' => 'Fuori dal nuovo CIDR'])
            ->assertSessionHasErrors('ip');

        $this->get(route('companies.sites.subnets.show', [$company, $site, $otherSubnet]))->assertNotFound();
        $this->delete(route('companies.sites.subnets.ip-addresses.destroy', [$otherCompany, $otherSite, $otherSubnet, $ip]), [
            'confirmed' => true,
        ])->assertNotFound();
        $this->actingAs(User::factory()->create(['is_superadmin' => false]));
        $this->patch(route('companies.sites.subnets.ip-addresses.update', [$company, $site, $subnet, $ip]), [
            'ip' => '192.168.1.201', 'description' => 'Non autorizzato',
        ])->assertForbidden();
        $this->assertDatabaseHas('ip_addresses', ['id' => $ip->id, 'address' => 3232235976, 'hostname' => 'server']);
        $this->assertDatabaseCount('ip_addresses', 2);
    }
}

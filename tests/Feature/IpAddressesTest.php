<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IpStatus;
use App\Models\User;
use App\Support\Ipv4Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IpAddressesTest extends TestCase
{
    use RefreshDatabase;

    public function test_ip_metadata_preserves_occupancy_and_keeps_tags_within_the_company(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $otherCompany = Company::create(['name' => 'Beta']);
        $foreignTag = $otherCompany->tags()->create(['name' => 'Privato Beta']);
        $status = IpStatus::create(['name' => 'Libero']);
        $show = route('companies.sites.subnets.show', [$company, $site, $subnet]);
        $store = route('companies.sites.subnets.ip-addresses.store', [$company, $site, $subnet]);

        $this->post($store, [
            'ip' => '10.0.0.1', 'hostname' => 'router', 'ip_status_id' => $status->id,
            'new_tags' => [['name' => 'Uffici', 'color' => 'blue'], ['name' => 'Critico', 'color' => 'red']],
        ])->assertSessionHasNoErrors();
        $ip = $subnet->ipAddresses()->sole();
        $update = route('companies.sites.subnets.ip-addresses.update', [$company, $site, $subnet, $ip]);

        $this->get($show)->assertInertia(fn (Assert $page) => $page
            ->where('firstProposableIp', '10.0.0.2')
            ->where('addressCounts', ['documented' => 1, 'excluded' => 0, 'external' => 0, 'proposable' => 253])
            ->where('ipAddresses.data.0.status.name', 'Libero')->has('ipAddresses.data.0.tags', 2)
            ->has('tags', 2)->where('ipAddresses.data.0.tags.0.color', 'red'));
        $this->get($show.'?search=Critico&sort=tags&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 1)->where('ipAddresses.data.0.ip', '10.0.0.1'));
        $this->get($show.'?sort=status&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 254)->where('ipAddresses.data.0.ip', '10.0.0.1')
            ->where('ipAddresses.data.1.ip', '10.0.0.2'));

        $this->patch($update, [
            'ip' => '10.0.0.1', 'hostname' => 'router', 'tags' => [['id' => $foreignTag->id, 'color' => 'purple']],
        ])->assertSessionHasErrors('tags.0.id');
        $this->assertDatabaseMissing('ip_address_tag', ['ip_address_id' => $ip->id, 'tag_id' => $foreignTag->id]);
        $this->assertDatabaseHas('tags', ['id' => $foreignTag->id, 'color' => 'gray']);
        $this->assertDatabaseHas('ip_addresses', ['id' => $ip->id, 'ip_status_id' => $status->id]);

        $tag = $company->tags()->where('name', 'Uffici')->sole();
        $this->patch($update, [
            'ip' => '10.0.0.1', 'hostname' => 'router', 'tags' => [['id' => $tag->id, 'color' => '#123456']],
        ])->assertSessionHasErrors('tags.0.color');
        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'color' => 'blue']);
        $this->patch($update, [
            'ip' => '10.0.0.1', 'hostname' => 'router', 'tags' => [['id' => $tag->id, 'color' => 'green']],
        ])->assertSessionHasNoErrors();
        $this->get($show)->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.data.0.tags.0.color', 'green'));

        $this->patch($update, [
            'ip' => '10.0.0.1', 'hostname' => 'router', 'ip_status_id' => null, 'tags' => [],
        ])->assertSessionHasNoErrors();
        $this->get($show)->assertInertia(fn (Assert $page) => $page
            ->where('firstProposableIp', '10.0.0.2')->where('addressCounts.documented', 1)
            ->where('ipAddresses.data.0.status', null)->has('ipAddresses.data.0.tags', 0));
    }

    public function test_ip_table_search_and_numeric_sort_cover_all_pages_and_stay_within_the_subnet(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
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
        $subnet->excludedRanges()->create([
            'start_address' => ip2long('10.0.0.20'), 'end_address' => ip2long('10.0.0.60'),
        ]);
        $otherSubnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.1.0/24'));
        $otherSubnet->ipAddresses()->create(['address' => ip2long('10.0.1.200'), 'hostname' => 'target']);
        $show = route('companies.sites.subnets.show', [$company, $site, $subnet]);

        $this->get($show)->assertInertia(fn (Assert $page) => $page
            ->where('query.sort', null)->where('query.direction', 'asc')
            ->where('addressCounts', ['documented' => 52, 'excluded' => 8, 'external' => 0, 'proposable' => 194])
            ->where('ipAddresses.total', 254)->where('ipAddresses.data.0.ip', '10.0.0.1')
            ->where('ipAddresses.data.0.id', null)->where('ipAddresses.data.1.ip', '10.0.0.2'));
        $this->assertDatabaseCount('ip_addresses', 53);
        $this->get($show.'?search=target')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 1)->where('ipAddresses.data.0.ip', '10.0.0.200'));
        $this->get($show.'?search=%25')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 1)->where('ipAddresses.data.0.hostname', 'target'));
        $this->get($show.'?search=node&sort=&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('query.sort', null)->where('query.direction', 'asc')
            ->where('ipAddresses.total', 51)->where('ipAddresses.data.0.ip', '10.0.0.2'));
        $this->get($show.'?search=10.0.0.&sort=ip&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.data.0.ip', '10.0.0.254')
            ->where('ipAddresses.data.1.ip', '10.0.0.253')
            ->where('ipAddresses.next_page_url', function ($url) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query === ['search' => '10.0.0.', 'sort' => 'ip', 'direction' => 'desc', 'page' => '2'];
            }));
    }

    public function test_virtual_rows_and_metadata_matches_share_global_sorting_without_duplicates(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/29'));
        $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.2'), 'hostname' => 'router']);
        $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.4'), 'hostname' => 'router']);
        $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.5'), 'description' => '10.0.0.2']);
        $show = route('companies.sites.subnets.show', [$company, $site, $subnet]);

        $this->get($show.'?search=10.0.0.2&sort=hostname')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 2)->where('ipAddresses.data.0.ip', '10.0.0.5')
            ->where('ipAddresses.data.1.ip', '10.0.0.2'));
        $this->get($show.'?sort=hostname&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 6)->where('ipAddresses.data.0.ip', '10.0.0.2')
            ->where('ipAddresses.data.1.ip', '10.0.0.4')->where('ipAddresses.data.2.ip', '10.0.0.1')
            ->where('ipAddresses.data.3.ip', '10.0.0.3')->where('ipAddresses.data.4.ip', '10.0.0.5')
            ->where('ipAddresses.data.5.ip', '10.0.0.6'));
        $this->assertDatabaseCount('ip_addresses', 3);
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
            ->where('ipAddresses.total', 254)->where('ipAddresses.data.9.ip', '192.168.1.10')
            ->where('ipAddresses.data.19.ip', '192.168.1.20'));

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
        foreach (['192.168.1.0', '192.168.1.255'] as $address) {
            $this->post($store, ['ip' => $address, 'hostname' => 'server'])->assertSessionHasErrors([
                'ip' => 'Inserisci un indirizzo host utilizzabile nella subnet 192.168.1.0/24, da 192.168.1.1 a 192.168.1.254.',
            ]);
        }
        $this->assertDatabaseCount('ip_addresses', 0);
        $this->post($store, ['ip' => '192.168.1.200', 'hostname' => 'server'])->assertSessionHasNoErrors();
        $ip = $subnet->ipAddresses()->sole();
        foreach (['192.168.1.0', '192.168.1.255'] as $address) {
            $this->patch(route('companies.sites.subnets.ip-addresses.update', [$company, $site, $subnet, $ip]), [
                'ip' => $address, 'hostname' => 'changed',
            ])->assertSessionHasErrors('ip');
        }
        $this->assertDatabaseHas('ip_addresses', ['id' => $ip->id, 'address' => ip2long('192.168.1.200'), 'hostname' => 'server']);
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

    public function test_31_and_32_addresses_can_be_created_and_updated(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);

        foreach (['192.0.2.0/31' => ['192.0.2.0', '192.0.2.1'], '192.0.2.10/32' => ['192.0.2.10']] as $cidr => $addresses) {
            $subnet = $site->subnets()->create(Ipv4Cidr::parse($cidr));
            foreach ($addresses as $address) {
                $this->post(route('companies.sites.subnets.ip-addresses.store', [$company, $site, $subnet]), [
                    'ip' => $address, 'hostname' => 'node',
                ])->assertSessionHasNoErrors();
                $ip = $subnet->ipAddresses()->where('address', ip2long($address))->sole();
                $this->patch(route('companies.sites.subnets.ip-addresses.update', [$company, $site, $subnet, $ip]), [
                    'ip' => $address, 'hostname' => 'updated',
                ])->assertSessionHasNoErrors();
                $this->assertDatabaseHas('ip_addresses', [
                    'id' => $ip->id, 'address' => ip2long($address), 'hostname' => 'updated',
                ]);
            }
            $this->get(route('companies.sites.subnets.show', [$company, $site, $subnet]))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('ipAddresses.total', count($addresses))
                    ->where('addressCounts.documented', count($addresses))
                    ->where('addressCounts.proposable', 0));
        }
    }

    public function test_cidr_changes_preserve_usable_documented_ips_including_31_and_32(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('192.0.2.0/24'));
        $ip = $subnet->ipAddresses()->create(['address' => ip2long('192.0.2.127'), 'hostname' => 'node']);
        $update = route('companies.sites.subnets.update', [$company, $site, $subnet]);
        $updateIp = route('companies.sites.subnets.ip-addresses.update', [$company, $site, $subnet, $ip]);

        foreach (['192.0.2.127' => '192.0.2.0/25', '192.0.2.128' => '192.0.2.128/25'] as $address => $cidr) {
            $this->patch($updateIp, ['ip' => $address, 'hostname' => 'node'])->assertSessionHasNoErrors();
            $this->patch($update, ['cidr' => $cidr])->assertSessionHasErrors([
                'cidr' => 'Il nuovo CIDR lascerebbe IP documentati fuori dagli indirizzi host utilizzabili. Modifica o libera prima gli indirizzi interessati.',
            ]);
            $this->assertDatabaseHas('subnets', ['id' => $subnet->id, 'cidr' => '192.0.2.0/24']);
            $this->assertDatabaseHas('ip_addresses', ['id' => $ip->id, 'address' => ip2long($address), 'hostname' => 'node']);
        }

        foreach (['192.0.2.128/31', '192.0.2.128/32'] as $cidr) {
            $this->patch($update, ['cidr' => $cidr])->assertSessionHasNoErrors();
            $this->assertDatabaseHas('subnets', ['id' => $subnet->id, 'cidr' => $cidr]);
            $this->assertDatabaseHas('ip_addresses', ['id' => $ip->id, 'address' => ip2long('192.0.2.128'), 'hostname' => 'node']);
        }
        $this->patch($update, ['cidr' => '192.0.2.128/30'])->assertSessionHasErrors('cidr');
        $this->assertDatabaseHas('subnets', ['id' => $subnet->id, 'cidr' => '192.0.2.128/32']);
        $this->assertDatabaseCount('ip_addresses', 1);
    }
}

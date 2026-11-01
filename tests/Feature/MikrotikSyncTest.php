<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Manufacturer;
use App\Models\MikrotikSource;
use App\Models\Subnet;
use App\Models\User;
use App\Support\Ipv4Cidr;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MikrotikSyncTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $site = Company::create(['name' => 'Synthetic company'])->sites()->create(['name' => 'Synthetic site']);
        $manufacturer = Manufacturer::create(['name' => 'MikroTik']);
        $this->device = $site->devices()->create(['name' => 'Synthetic router', 'manufacturer_id' => $manufacturer->id]);
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->put($this->url('update'), ['server_url' => 'https://10.2.0.1', 'username' => 'reader', 'password' => 'synthetic-secret', 'allow_unverified_certificate' => false, 'revision' => null])->assertSessionHasNoErrors();
        $this->device->mikrotikConnection()->sole()->update(['check_status' => 'ok', 'checked_at' => now()]);
    }

    public function test_activation_imports_only_explicit_data_and_refresh_preserves_manual_records(): void
    {
        $tables = $this->tables();
        $tables['system/resource'][0]['board-name'] = 'CCR2004-1G-12S+2XS';
        $tables['interface/ethernet'][0]['switch'] = 'switch1';
        $this->fake($tables);
        $this->artisan('mikrotik:sync')->assertSuccessful();
        Http::assertNothingSent();
        $this->device->mikrotikConnection()->sole()->update(['check_status' => null, 'checked_at' => null]);
        $this->post($this->url('verify'), $this->operation())->assertSessionHasNoErrors();
        $this->assertSame('ok', $this->device->mikrotikConnection()->sole()->check_status);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $subnet = $this->device->site->subnets()->sole();
        $this->assertSame('192.0.2.0/29', $subnet->cidr);
        $this->assertDatabaseCount('vlans', 2);
        $this->assertDatabaseCount('ip_addresses', 1);
        $this->assertSame($this->device->id, $subnet->ipAddresses()->sole()->device_id);
        $this->assertSame('192.0.2.4', $subnet->firstProposableIp());
        $this->assertSame(['documented' => 1, 'excluded' => 0, 'external' => 2, 'proposable' => 3], $subnet->addressCounts());
        $this->assertDatabaseHas('mikrotik_sources', ['kind' => 'lease', 'address' => ip2long('192.0.2.3'), 'ip_address_id' => null]);
        $subnet->update(['name' => 'Manual name', 'description' => 'Manual notes']);
        $subnet->ipAddresses()->create(['address' => ip2long('192.0.2.5'), 'hostname' => 'manual-host']);
        $tables['interface'][1]['name'] = 'renamed-vlan';
        $tables['interface/vlan'][0]['name'] = 'renamed-vlan';
        $tables['ip/address'][0]['interface'] = 'renamed-vlan';
        $tables['ip/dhcp-server'][0]['interface'] = 'renamed-vlan';
        $tables['ip/address'][] = [...$tables['ip/address'][0], '.id' => '*2', 'address' => '192.0.2.6/29'];
        $this->fake($tables);
        $this->post($this->url('sync'), $this->operation())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subnets', 1);
        $this->assertDatabaseCount('ip_addresses', 3);
        $this->assertSame('Manual notes', $subnet->fresh()->description);
        $this->assertSame(0, MikrotikSource::where('kind', 'subnet')->whereNotNull('problem')->count());
        $this->assertNull(MikrotikSource::where('kind', 'lease')->sole()->problem);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), '.proplist='));
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_incomplete_reads_preserve_observations_until_a_complete_read_reports_absence(): void
    {
        $tables = $this->tables();
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $subnet = $this->device->site->subnets()->sole();
        $seen = MikrotikSource::where('kind', 'observation')->sole()->seen_at;
        $this->travel(61)->seconds();
        $this->fake($tables, 'ip/dhcp-server/lease');
        $this->post($this->url('sync'), $this->operation())->assertSessionHasErrors('connection');
        $this->assertSame($seen->toIso8601String(), MikrotikSource::where('kind', 'observation')->sole()->seen_at->toIso8601String());
        $this->assertSame('192.0.2.4', $subnet->firstProposableIp());
        $malformed = $tables;
        $malformed['ip/arp'][0]['address'] = 'invalid-address';
        $this->travel(61)->seconds();
        $this->fake($malformed);
        $this->post($this->url('sync'), $this->operation())->assertSessionHasErrors('connection');
        $this->assertSame('192.0.2.4', $subnet->firstProposableIp());
        $tables['ip/arp'] = [];
        $this->travel(61)->seconds();
        $this->fake($tables);
        $this->post($this->url('sync'), $this->operation())->assertSessionHasNoErrors();
        $this->assertSame('192.0.2.2', $subnet->firstProposableIp());
        $this->assertDatabaseCount('ip_addresses', 1);
    }

    public function test_endpoint_change_suspends_sync_and_keeps_historical_source_and_ipam(): void
    {
        $this->fake($this->tables());
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $this->put($this->url('update'), [...$this->operation(), 'server_url' => 'https://10.2.0.2', 'username' => 'reader', 'password' => 'new-synthetic-secret', 'allow_unverified_certificate' => false])->assertSessionHasNoErrors();
        $this->assertNull($this->device->mikrotikConnection()->sole()->activated_at);
        $this->assertDatabaseHas('mikrotik_sources', ['kind' => 'observation', 'state' => 'unknown']);
        $this->assertDatabaseCount('ip_addresses', 1);
        $this->post($this->url('sync'), $this->operation())->assertSessionHasErrors('connection');
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://10.2.0.2'));
        $this->delete($this->url('destroy'), $this->operation())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('mikrotik_connections', 0);
        $this->assertSame('192.0.2.4', $this->device->site->subnets()->sole()->firstProposableIp());
    }

    public function test_two_sources_need_context_confirmation_and_cannot_steal_device_assignment(): void
    {
        $this->fake($this->tables());
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $first = $this->device;
        $this->device = $first->site->devices()->create(['name' => 'Second router', 'manufacturer_id' => $first->manufacturer_id]);
        $this->put($this->url('update'), ['server_url' => 'https://10.2.0.1', 'username' => 'reader', 'password' => 'synthetic-secret', 'allow_unverified_certificate' => false, 'revision' => null])->assertSessionHasNoErrors();
        $this->device->mikrotikConnection()->sole()->update(['check_status' => 'ok', 'checked_at' => now()]);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $source = $this->device->mikrotikSources()->where('kind', 'vlan')->where('data->tag', 10)->sole();
        $this->assertNotNull($source->problem);
        $this->post($this->url('associate'), [...$this->operation(), 'source_id' => $source->id])->assertSessionHasNoErrors();
        $this->post($this->url('sync'), $this->operation())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subnets', 1);
        $this->assertSame($first->id, $first->site->subnets()->sole()->ipAddresses()->sole()->device_id);
        $this->assertSame(2, MikrotikSource::where('kind', 'subnet')->whereNotNull('subnet_id')->count());
    }

    public function test_overlap_is_retained_as_external_data_and_access_remains_scoped(): void
    {
        $this->device->site->subnets()->create(Ipv4Cidr::parse('192.0.2.0/28'));
        $this->fake($this->tables());
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subnets', 1);
        $this->assertDatabaseCount('ip_addresses', 0);
        $this->assertStringContainsString('Sovrapposizione', MikrotikSource::where('kind', 'subnet')->sole()->problem);
        $member = User::factory()->create(['is_superadmin' => false]);
        $this->device->site->company->users()->attach($member);
        $this->actingAs($member)->post($this->url('sync'), $this->operation())->assertForbidden();
        $subnet = Subnet::sole();
        $this->get(route('companies.sites.subnets.show', [$this->device->site->company, $this->device->site, $subnet]))
            ->assertOk()->assertDontSee('synthetic-secret')->assertDontSee('https://10.2.0.1')
            ->assertInertia(fn (Assert $page) => $page->has('mikrotikSources')->where('firstProposableIp', '192.0.2.4'));
        $this->actingAs(User::factory()->create(['is_superadmin' => false]))
            ->get(route('companies.sites.subnets.show', [$this->device->site->company, $this->device->site, $subnet]))->assertForbidden();
    }

    public function test_planned_mac_is_kept_and_recognized_without_copying_observed_identity(): void
    {
        $tables = $this->tables();
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $subnet = Subnet::sole();
        $ip = $subnet->ipAddresses()->create(['address' => ip2long('192.0.2.3'), 'description' => 'Manual client', 'mac_address' => '02:00:00:00:00:03']);
        $url = route('companies.sites.subnets.ip-addresses.update', [$this->device->site->company, $this->device->site, $subnet, $ip]);
        $data = ['ip' => '192.0.2.3', 'description' => 'Manual client', 'mac_address' => '02:00:00:00:00:33'];
        $this->patch($url, $data)->assertSessionHasErrors('mikrotik_edit_mode');
        $this->patch($url, [...$data, 'mikrotik_edit_mode' => 'planned'])->assertSessionHasNoErrors();
        $tables['ip/dhcp-server/lease'][0]['mac-address'] = '02:00:00:00:00:33';
        $this->fake($tables);
        $this->post($this->url('sync'), $this->operation())->assertSessionHasNoErrors();
        $this->assertNotNull($ip->fresh()->mikrotik_matched_at);
        $this->assertSame('Manual client', $ip->fresh()->description);
        $this->assertNull($ip->fresh()->device_id);
    }

    public function test_missing_flags_non_fixed_leases_and_dynamic_observations_never_create_documentation(): void
    {
        $tables = $this->tables();
        unset($tables['ip/address'][0]['invalid']);
        $tables['ip/dhcp-server/lease'] = [
            ['.id' => '*1', 'address' => 'pool', 'dynamic' => 'false'],
            ['.id' => '*2', 'address' => '0.0.0.0', 'dynamic' => 'false'],
            ['.id' => '*3', 'address' => '192.0.2.4', 'dynamic' => 'true', 'status' => 'bound', 'host-name' => 'observed-client'],
        ];
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ip_addresses', 0);
        $this->assertDatabaseCount('subnets', 0);
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseHas('mikrotik_sources', ['kind' => 'observation', 'address' => ip2long('192.0.2.4')]);
        $this->assertDatabaseMissing('mikrotik_sources', ['address' => 0]);
    }

    public function test_dynamic_and_disabled_addresses_remain_external_without_actionable_warnings(): void
    {
        $tables = $this->tables();
        $tables['ip/address'][] = [...$tables['ip/address'][0], '.id' => '*2', 'address' => '198.51.100.1/29', 'dynamic' => 'true'];
        $tables['ip/address'][] = [...$tables['ip/address'][0], '.id' => '*3', 'address' => '203.0.113.1/29', 'disabled' => 'true'];
        $tables['ip/dhcp-server/lease'][0]['disabled'] = 'true';
        $tables['ip/dhcp-server/lease'][] = [...$tables['ip/dhcp-server/lease'][0], '.id' => '*2', 'address' => '192.0.2.4', 'dynamic' => 'true', 'disabled' => 'false'];
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subnets', 1);
        $this->assertDatabaseCount('ip_addresses', 1);
        $this->assertSame(0, $this->device->mikrotikConnection()->sole()->sync_summary['problems']);
        $skipped = $this->device->mikrotikSources()->whereIn('kind', ['subnet', 'address', 'lease'])->whereNotNull('problem')->get();
        $this->assertCount(5, $skipped);
        foreach ($skipped as $source) {
            $this->assertNull($source->ip_address_id);
            $this->assertNull($source->subnet_id);
            $this->assertNull($source->display()['problem']);
            $this->assertNotNull($source->display()['import_note']);
        }
        $dynamic = $this->device->mikrotikSources()->where('kind', 'observation')->where('address', ip2long('192.0.2.4'))->sole();
        $this->assertSame('true', $dynamic->display()['data']['dynamic']);
        $this->assertNull($dynamic->ip_address_id);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    #[TestWith(['unique'])]
    #[TestWith(['missing'])]
    #[TestWith(['ambiguous'])]
    public function test_lease_without_server_uses_only_an_unambiguous_device_subnet(string $scenario): void
    {
        $tables = $this->tables();
        unset($tables['ip/dhcp-server/lease'][0]['server']);
        // Inactive and dynamic networks must not make the matching subnet ambiguous.
        $tables['ip/address'][] = [...$tables['ip/address'][0], '.id' => '*2', 'address' => '192.0.2.1/28', 'disabled' => 'true'];
        $tables['ip/address'][] = [...$tables['ip/address'][0], '.id' => '*3', 'address' => '192.0.2.1/27', 'dynamic' => 'true'];
        if ($scenario === 'missing') {
            $tables['ip/dhcp-server/lease'][0]['address'] = '198.51.100.3';
        } elseif ($scenario === 'ambiguous') {
            $tables['ip/address'][1]['disabled'] = 'false';
        }
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();

        $source = $this->device->mikrotikSources()->where('kind', 'lease')->sole();
        $this->assertNull($source->data['server']);
        $this->assertNull($source->ip_address_id);
        if ($scenario === 'unique') {
            $this->assertNull($source->problem);
            $this->assertSame($this->device->site->subnets()->sole()->id, $source->subnet_id);
            $this->assertSame('ip/address:*1', $source->data['network_key']);
        } else {
            $this->assertNull($source->subnet_id);
            $this->assertNull($source->data['network_key']);
            $this->assertStringContainsString($scenario === 'missing' ? 'nessuna subnet' : 'più subnet', $source->problem);
        }
        $this->assertDatabaseCount('ip_addresses', 1);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    #[TestWith(['lease', 'server', 'not-present', 'non è presente nella lettura'])]
    #[TestWith(['lease', 'mac-address', '00:00:00:00:00:00', 'MAC nullo'])]
    #[TestWith(['server', 'invalid', null, 'non riporta il campo invalid'])]
    #[TestWith(['server', 'invalid', 'true', 'invalid=true'])]
    public function test_dhcp_missing_data_and_invalid_values_have_distinct_reasons(string $target, string $field, ?string $value, string $reason): void
    {
        $tables = $this->tables();
        $menu = $target === 'lease' ? 'ip/dhcp-server/lease' : 'ip/dhcp-server';
        if ($value === null) {
            unset($tables[$menu][0][$field]);
        } else {
            $tables[$menu][0][$field] = $value;
        }
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $source = $this->device->mikrotikSources()->where('kind', 'lease')->sole();
        $this->assertStringContainsString($reason, $source->display()['problem']);
        $this->assertNull($source->display()['import_note']);
        $this->assertNull($source->subnet_id);
        $this->assertNull($source->ip_address_id);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_concurrent_connection_change_cannot_apply_the_old_snapshot(): void
    {
        $tables = $this->tables();
        Http::fake(['https://10.2.0.1/rest/*' => function (Request $request) use ($tables) {
            $menu = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/rest/'));
            if ($menu === 'system/identity') {
                $this->device->mikrotikConnection()->update(['revision' => (string) Str::uuid()]);
            }

            return Http::response($tables[$menu]);
        }]);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasErrors('connection');
        $this->assertDatabaseCount('mikrotik_sources', 0);
        $this->assertDatabaseCount('subnets', 0);
    }

    public function test_bridge_vlan_contexts_do_not_turn_dynamic_pvids_or_ambiguous_ranges_into_documented_vlans(): void
    {
        $tables = $this->tables();
        $tables['system/resource'][0]['board-name'] = 'CCR2004-1G-12S+2XS';
        $tables['interface/ethernet'][0]['switch'] = 'switch1';
        $tables['interface'][] = ['.id' => '*4', 'name' => 'bridge', 'type' => 'bridge'];
        $tables['interface/bridge'] = [['.id' => '*1', 'name' => 'bridge', 'vlan-filtering' => 'true', 'disabled' => 'false']];
        $tables['interface/bridge/vlan'] = [
            ['.id' => '*1', 'bridge' => 'bridge', 'vlan-ids' => '100-101', 'tagged' => 'bridge', 'dynamic' => 'false', 'disabled' => 'false'],
            ['.id' => '*2', 'bridge' => 'bridge', 'vlan-ids' => '1', 'dynamic' => 'true'],
            ['.id' => '*3', 'bridge' => 'bridge', 'vlan-ids' => '200-201', 'untagged' => 'ether1', 'dynamic' => 'false', 'disabled' => 'false'],
            ['.id' => '*4', 'bridge' => 'bridge', 'vlan-ids' => '10', 'dynamic' => 'false', 'disabled' => 'false'],
        ];
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $this->assertSame([20, 100, 101], $this->device->site->vlans()->orderBy('tag')->pluck('tag')->all());
        $this->assertDatabaseCount('subnets', 0);
        $this->assertSame(2, MikrotikSource::where('kind', 'vlan')->where('data->tag', 10)->whereNotNull('problem')->count());
    }

    public function test_database_rejects_a_source_associated_with_a_different_site(): void
    {
        $this->fake($this->tables());
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $other = Company::create(['name' => 'Other synthetic company'])->sites()->create(['name' => 'Other site']);
        $source = $this->device->mikrotikSources()->firstOrFail();
        $source->site_id = $other->id;
        $this->expectException(QueryException::class);
        $source->save();
    }

    public function test_observed_values_are_searchable_and_sorted_across_pages_without_documenting_them(): void
    {
        $tables = $this->tables();
        $tables['ip/address'][0]['address'] = '192.0.2.1/26';
        $tables['ip/dhcp-server/network'][0]['address'] = '192.0.2.0/26';
        $tables['ip/dhcp-server/lease'] = array_map(fn ($last) => [
            '.id' => '*'.dechex($last), 'address' => '192.0.2.'.$last,
            'mac-address' => sprintf('02:00:00:00:00:%02X', $last),
            'host-name' => sprintf('host-%03d', $last), 'dynamic' => 'true',
        ], range(2, 56));
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $subnet = $this->device->site->subnets()->sole();
        $subnet->ipAddresses()->create(['address' => ip2long('192.0.2.3'), 'hostname' => 'zz-manual', 'mac_address' => '02:00:00:00:00:FF']);
        $url = route('companies.sites.subnets.show', [$this->device->site->company, $this->device->site, $subnet]);
        $this->get($url.'?search=host&sort=hostname&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 55)->where('ipAddresses.data.0.ip', '192.0.2.3')
            ->where('ipAddresses.data.0.hostname', 'zz-manual')->where('ipAddresses.data.1.ip', '192.0.2.56')
            ->where('ipAddresses.data.1.id', null)->where('ipAddresses.data.1.hostname', null));
        $this->get($url.'?hide_free=1&search=host&sort=hostname&direction=desc&per_page=50&page=2')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 55)->has('ipAddresses.data', 5)
            ->where('ipAddresses.data.0.ip', '192.0.2.7')->where('ipAddresses.data.4.ip', '192.0.2.2'));
        $this->get($url.'?search=host&sort=hostname')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 55)->where('ipAddresses.data.0.ip', '192.0.2.2'));
        $this->get($url.'?search=host&sort=mac_address&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 55)->where('ipAddresses.data.0.mac_address', '02:00:00:00:00:FF')
            ->where('ipAddresses.data.1.ip', '192.0.2.56'));
        $this->assertDatabaseCount('ip_addresses', 2);
    }

    public function test_hiding_free_ips_keeps_documented_and_external_addresses_without_duplicates(): void
    {
        $this->fake($this->tables());
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $subnet = $this->device->site->subnets()->sole();
        $subnet->ipAddresses()->create(['address' => ip2long('192.0.2.5'), 'description' => 'Manual']);
        $subnet->excludedRanges()->create(['start_address' => ip2long('192.0.2.4'), 'end_address' => ip2long('192.0.2.4')]);
        $url = route('companies.sites.subnets.show', [$this->device->site->company, $this->device->site, $subnet]);

        $this->get($url.'?hide_free=1')->assertInertia(fn (Assert $page) => $page
            ->where('query.hide_free', 1)->where('ipAddresses.total', 4)
            ->where('ipAddresses.data.0.ip', '192.0.2.1')
            ->where('ipAddresses.data.1.ip', '192.0.2.2')
            ->where('ipAddresses.data.2.ip', '192.0.2.3')
            ->where('ipAddresses.data.3.ip', '192.0.2.5'));
        $this->get($url.'?hide_free=1&sort=description&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 4)->where('ipAddresses.data.0.ip', '192.0.2.5')
            ->where('ipAddresses.data.1.ip', '192.0.2.1'));
        $this->get($url.'?hide_free=1&search=192.0.2.4')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 0)->has('ipAddresses.data', 0));
        $this->get($url.'?hide_free=0&search=192.0.2.4')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 1)->where('ipAddresses.data.0.ip', '192.0.2.4'));
        $this->assertDatabaseCount('ip_addresses', 2);
    }

    public function test_exclusions_preserve_documentation_and_survive_sync_until_explicitly_reincluded(): void
    {
        $tables = $this->tables();
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $site = $this->device->site;
        $subnet = $site->subnets()->sole();
        $vlan = $subnet->vlan;
        $manual = $subnet->ipAddresses()->create(['address' => ip2long('192.0.2.5'), 'hostname' => 'preserved-host', 'description' => 'Manual notes']);
        $subnetUrl = route('companies.sites.subnets.exclusion.update', [$site->company, $site, $subnet]);
        $vlanUrl = route('companies.sites.vlans.exclusion.update', [$site->company, $site, $vlan]);
        $searchUrl = route('companies.sites.search.index', [$site->company, $site, 'search' => 'preserved-host']);
        $this->get($searchUrl)->assertInertia(fn (Assert $page) => $page->where('results.total', 1));

        $this->put($subnetUrl, ['excluded' => true])->assertSessionHasNoErrors();
        $this->put($vlanUrl, ['excluded' => true])->assertSessionHasNoErrors();
        $tables['ip/address'][] = [...$tables['ip/address'][0], '.id' => '*2', 'address' => '192.0.2.6/29'];
        $tables['ip/address'][] = [...$tables['ip/address'][0], '.id' => '*3', 'address' => '198.51.100.1/29'];
        $this->fake($tables);
        $this->post($this->url('sync'), $this->operation())->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subnets', 1);
        $this->assertDatabaseCount('ip_addresses', 2);
        $this->assertSame('Manual notes', $manual->fresh()->description);
        $this->assertSame($vlan->id, $subnet->fresh()->vlan_id);
        $this->assertSame('192.0.2.4', $subnet->firstProposableIp());
        $this->get($searchUrl)->assertInertia(fn (Assert $page) => $page->where('results.total', 0));
        $this->post(route('companies.sites.subnets.store', [$site->company, $site]), ['cidr' => $subnet->cidr])->assertSessionHasErrors('cidr');
        $this->put($subnetUrl, ['excluded' => false])->assertSessionHasErrors('excluded');

        $this->fake($tables);
        $this->put($vlanUrl, ['excluded' => false])->assertSessionHasNoErrors();
        $this->assertNotNull($subnet->fresh()->excluded_at);
        $this->put($subnetUrl, ['excluded' => false])->assertSessionHasNoErrors();
        Http::assertNothingSent();
        $this->get($searchUrl)->assertInertia(fn (Assert $page) => $page->where('results.total', 1));
        $this->post($this->url('sync'), $this->operation())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subnets', 2);
        $this->assertDatabaseHas('ip_addresses', ['subnet_id' => $subnet->id, 'address' => ip2long('192.0.2.6')]);
        $this->assertSame('Manual notes', $manual->fresh()->description);
    }

    public function test_an_unassociated_source_can_be_excluded_and_restored_without_importing_its_children(): void
    {
        $tables = $this->tables();
        $tables['interface/vlan'][1]['disabled'] = 'true';
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $source = $this->device->mikrotikSources()->where('kind', 'vlan')->where('data->tag', 20)->sole();
        $site = $this->device->site;
        $url = route('companies.sites.devices.mikrotik.sources.exclusion.update', [$site->company, $site, $this->device, $source->id]);
        $this->put($url, ['excluded' => true])->assertSessionHasNoErrors();
        $tables['interface/vlan'][1]['disabled'] = 'false';
        $tables['ip/address'][] = [...$tables['ip/address'][0], '.id' => '*2', 'address' => '198.51.100.1/29', 'interface' => 'vlan20'];
        $this->fake($tables);

        $this->post($this->url('sync'), $this->operation())->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('vlans', ['site_id' => $site->id, 'tag' => 20]);
        $this->assertDatabaseMissing('subnets', ['site_id' => $site->id, 'cidr' => '198.51.100.0/29']);
        $this->put($url, ['excluded' => false])->assertSessionHasNoErrors();
        $this->post($this->url('sync'), $this->operation())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subnets', ['site_id' => $site->id, 'cidr' => '198.51.100.0/29']);
    }

    public function test_exclusion_endpoints_reject_other_sites_and_unauthorized_users(): void
    {
        $site = $this->device->site;
        $other = Company::create(['name' => 'Other company'])->sites()->create(['name' => 'Other site']);
        $subnet = $other->subnets()->create(Ipv4Cidr::parse('203.0.113.0/24'));
        $wrong = [$site->company, $site, $subnet];
        $this->get(route('companies.sites.subnets.exclusion.preview', $wrong))->assertNotFound();
        $this->put(route('companies.sites.subnets.exclusion.update', $wrong), ['excluded' => true])->assertNotFound();
        $this->actingAs(User::factory()->create(['is_superadmin' => false]))
            ->put(route('companies.sites.subnets.exclusion.update', [$other->company, $other, $subnet]), ['excluded' => true])->assertForbidden();
        $this->assertNull($subnet->fresh()->excluded_at);
    }

    public function test_physical_interface_mapping_tracks_router_values_without_overwriting_manual_data(): void
    {
        $tables = $this->tables();
        $tables['interface/ethernet'][0]['mac-address'] = '02:00:00:00:00:01';
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $source = $this->device->mikrotikSources()->where('kind', 'interface')->sole();
        $model = DeviceModel::create(['name' => 'Synthetic model', 'manufacturer_id' => $this->device->manufacturer_id,
            'panel' => ['faces' => ['front' => ['ports' => [['id' => 'physical-port', 'type' => 'rj45', 'label' => '1']]], 'rear' => ['ports' => []]]]]);
        $this->device->update(['device_model_id' => $model->id]);
        $interface = $this->device->interfaces()->create(['connector_id' => 'physical-port', 'kind' => 'physical']);
        $route = [$this->device->site->company, $this->device->site, $this->device, $interface];
        $url = route('companies.sites.devices.interfaces.update', $route);
        $payload = ['name' => null, 'mac_address' => null, 'mikrotik_source_id' => $source->id];
        $this->assertNull($source->device_interface_id);

        $this->patch($url, $payload)->assertSessionHasNoErrors();
        $this->getJson(route('companies.sites.devices.interfaces.show', $route))
            ->assertOk()->assertJsonPath('interface.mikrotik.name', 'ether1')
            ->assertJsonPath('interface.mikrotik.mac_address', '02:00:00:00:00:01')
            ->assertJsonMissingPath('interface.mikrotik_source')
            ->assertDontSee('synthetic-secret');
        $this->patch($url, [...$payload, 'name' => 'Manual alias', 'mac_address' => '02:00:00:00:00:99'])
            ->assertSessionHasNoErrors();
        $this->assertSame($interface->id, $source->fresh()->device_interface_id);

        $tables['interface/ethernet'][0]['name'] = 'uplink';
        $tables['interface'][0]['name'] = 'uplink';
        foreach ($tables['interface/vlan'] as &$vlan) {
            $vlan['interface'] = 'uplink';
        }
        unset($vlan);
        $tables['interface/ethernet'][0]['mac-address'] = '02:00:00:00:00:02';
        $this->fake($tables);
        $this->post($this->url('sync'), $this->operation())->assertSessionHasNoErrors();
        $interface->refresh();
        $this->assertSame('Manual alias', $interface->name);
        $this->assertSame('02:00:00:00:00:99', $interface->mac_address);
        $this->assertSame('uplink', $interface->mikrotik['name']);
        $this->assertSame('02:00:00:00:00:02', $interface->mikrotik['mac_address']);
        $this->assertFalse($interface->mikrotik['stale']);

        $this->travel(61)->seconds();
        $this->fake($tables, 'interface/ethernet');
        $this->post($this->url('sync'), $this->operation())->assertSessionHasErrors('connection');
        $this->assertTrue($interface->fresh()->mikrotik['stale']);
        $this->assertSame('uplink', $interface->fresh()->mikrotik['name']);
        $tables['interface/ethernet'] = [];
        $this->fake($tables);
        $this->post($this->url('sync'), $this->operation())->assertSessionHasNoErrors();
        $this->assertTrue($interface->fresh()->mikrotik['stale']);
        $this->assertDatabaseCount('device_interfaces', 1);
        $this->patch($url, ['name' => 'Manual alias', 'mac_address' => '02:00:00:00:00:99', 'mikrotik_source_id' => null])
            ->assertSessionHasNoErrors();
        $this->assertNull($interface->fresh()->mikrotik);
        $this->assertModelExists($source);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_interface_mapping_rejects_duplicate_and_cross_device_links(): void
    {
        $this->fake($this->tables());
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $source = $this->device->mikrotikSources()->where('kind', 'interface')->sole();
        $model = DeviceModel::create(['name' => 'Synthetic model', 'manufacturer_id' => $this->device->manufacturer_id,
            'panel' => ['faces' => ['front' => ['ports' => [
                ['id' => 'first', 'type' => 'rj45', 'label' => '1'],
                ['id' => 'second', 'type' => 'rj45', 'label' => '2'],
            ]], 'rear' => ['ports' => []]]]]);
        $this->device->update(['device_model_id' => $model->id]);
        $first = $this->device->interfaces()->create(['connector_id' => 'first', 'kind' => 'physical']);
        $second = $this->device->interfaces()->create(['connector_id' => 'second', 'kind' => 'physical']);
        $site = $this->device->site;
        $url = fn ($device, $interface) => route('companies.sites.devices.interfaces.update', [$site->company, $site, $device, $interface]);
        $payload = ['name' => null, 'mac_address' => null, 'mikrotik_source_id' => $source->id];
        $this->patch($url($this->device, $first), $payload)->assertSessionHasNoErrors();
        $this->patch($url($this->device, $second), $payload)->assertSessionHasErrors('mikrotik_source_id');
        $other = $site->devices()->create(['name' => 'Other router', 'manufacturer_id' => $this->device->manufacturer_id]);
        $foreign = $other->interfaces()->create(['connector_id' => 'foreign', 'kind' => 'physical']);
        $this->patch($url($other, $foreign), $payload)->assertSessionHasErrors('mikrotik_source_id');
        $this->assertSame($first->id, $source->fresh()->device_interface_id);
        $source->device_interface_id = $foreign->id;
        $this->expectException(QueryException::class);
        $source->save();
    }

    #[TestWith(['rj45', 'sfp-sfpplus1', 'sfp'])]
    #[TestWith(['sfp', 'ether1', 'rj45'])]
    #[TestWith(['qsfp', 'sfp28-1', 'sfp'])]
    #[TestWith(['rj45', 'unknown-port', null])]
    public function test_port_mapping_rejects_incompatible_factory_types_even_when_labels_match(string $portType, string $factoryName, ?string $sourceType): void
    {
        $ports = [['id' => 'wrong', 'type' => $portType, 'label' => $factoryName]];
        if ($sourceType !== null) {
            $ports[] = ['id' => 'compatible', 'type' => $sourceType, 'label' => 'Manual'];
        }
        $model = DeviceModel::create(['name' => 'Synthetic model', 'manufacturer_id' => $this->device->manufacturer_id,
            'panel' => ['faces' => ['front' => ['ports' => $ports], 'rear' => ['ports' => []]]]]);
        $this->device->update(['device_model_id' => $model->id]);
        $this->device->addModelInterfaces($model);
        $tables = $this->tables();
        $tables['interface/ethernet'][0]['default-name'] = $factoryName;
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $source = $this->device->mikrotikSources()->where('kind', 'interface')->sole();
        $this->assertNull($source->device_interface_id);
        $this->assertSame($sourceType, $source->interfaceDetails()['port_type']);
        $interfaces = $this->device->interfaces()->get()->keyBy('connector_id');
        $site = $this->device->site;
        $url = fn ($interface) => route('companies.sites.devices.interfaces.update', [$site->company, $site, $this->device, $interface]);
        $payload = ['name' => null, 'mac_address' => null, 'mikrotik_source_id' => $source->id];
        $this->patch($url($interfaces['wrong']), $payload)->assertSessionHasErrors('mikrotik_source_id');
        $this->assertNull($source->fresh()->device_interface_id);
        if ($sourceType !== null) {
            $this->patch($url($interfaces['compatible']), $payload)->assertSessionHasNoErrors();
            $this->assertSame($interfaces['compatible']->id, $source->fresh()->device_interface_id);
        }
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_model_labels_associate_physical_ports_by_factory_name_and_preserve_explicit_links(): void
    {
        $model = DeviceModel::create([
            'name' => 'Synthetic model', 'manufacturer_id' => $this->device->manufacturer_id,
            'panel' => ['faces' => ['front' => ['ports' => [
                ['id' => 'first', 'type' => 'rj45', 'label' => '1'],
                ['id' => 'second', 'type' => 'rj45', 'label' => 'ether2'],
                ['id' => 'ambiguous', 'type' => 'rj45', 'label' => '3'],
                ['id' => 'optical', 'type' => 'sfp', 'label' => 'SFP1'],
                ['id' => 'ambiguous-optical', 'type' => 'sfp', 'label' => 'SFP2'],
                ['id' => 'alias-a', 'type' => 'rj45', 'label' => '4'],
                ['id' => 'alias-b', 'type' => 'rj45', 'label' => 'ether4'],
            ]], 'rear' => ['ports' => []]]],
        ]);
        $this->device->update(['device_model_id' => $model->id]);
        $this->device->addModelInterfaces($model);
        $first = $this->device->interfaces()->where('connector_id', 'first')->sole();
        $first->update(['name' => 'Manual alias', 'mac_address' => '02:00:00:00:00:99']);
        $tables = $this->tables();
        foreach ([['*4', 'uplink', 'ether2'], ['*5', 'third-a', 'ether3'], ['*6', 'third-b', 'ether3'], ['*7', 'fiber', 'sfp-sfpplus1'], ['*8', 'optical-a', 'sfp2'], ['*9', 'optical-b', 'sfp-sfpplus2'], ['*A', 'fourth', 'ether4']] as [$id, $name, $defaultName]) {
            $tables['interface'][] = ['.id' => $id, 'name' => $name, 'type' => 'ether'];
            $tables['interface/ethernet'][] = ['.id' => $id, 'name' => $name, 'default-name' => $defaultName, 'mac-address' => '02:00:00:00:00:02'];
        }
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $interfaces = $this->device->interfaces()->get()->keyBy('connector_id');
        $this->assertSame('ether1', $interfaces['first']->mikrotik['default_name']);
        $this->assertSame('Manual alias', $interfaces['first']->name);
        $this->assertSame('02:00:00:00:00:99', $interfaces['first']->mac_address);
        $this->assertSame('uplink', $interfaces['second']->mikrotik['name']);
        $this->assertSame('02:00:00:00:00:02', $interfaces['second']->mikrotik['mac_address']);
        $this->assertSame('fiber', $interfaces['optical']->mikrotik['name']);
        $this->assertNull($interfaces['ambiguous']->mikrotik);
        $this->assertNull($interfaces['ambiguous-optical']->mikrotik);
        $this->assertNull($interfaces['alias-a']->mikrotik);
        $this->assertNull($interfaces['alias-b']->mikrotik);

        $manual = $this->device->interfaces()->create(['name' => 'Manual port', 'kind' => 'physical']);
        $sourceId = $interfaces['second']->mikrotik['id'];
        $site = $this->device->site;
        $url = fn ($interface) => route('companies.sites.devices.interfaces.update', [$site->company, $site, $this->device, $interface]);
        $this->patch($url($interfaces['second']), ['name' => null, 'mac_address' => null, 'mikrotik_source_id' => null])->assertSessionHasNoErrors();
        $this->patch($url($manual), ['name' => 'Manual port', 'kind' => 'physical', 'mac_address' => null, 'mikrotik_source_id' => $sourceId])->assertSessionHasNoErrors();
        $this->post($this->url('sync'), $this->operation())->assertSessionHasNoErrors();
        $this->assertSame($manual->id, MikrotikSource::findOrFail($sourceId)->device_interface_id);
        $this->assertNull($interfaces['second']->fresh()->mikrotik);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_catalog_mapping_preview_and_model_corrections_use_the_same_interfaces(): void
    {
        $ports = [
            ['id' => 'first', 'type' => 'rj45', 'label' => '1'],
            ['id' => 'second', 'type' => 'rj45', 'label' => '2'],
            ['id' => 'optical', 'type' => 'sfp', 'label' => ''],
        ];
        $model = DeviceModel::create(['name' => 'Synthetic model', 'manufacturer_id' => $this->device->manufacturer_id,
            'panel' => ['faces' => ['front' => ['ports' => $ports], 'rear' => ['ports' => []]]]]);
        $this->device->update(['device_model_id' => $model->id]);
        $this->device->addModelInterfaces($model);
        $tables = $this->tables();
        foreach ([['*4', 'uplink', 'ether2'], ['*5', 'fiber', 'sfp-sfpplus1']] as [$id, $name, $factory]) {
            $tables['interface'][] = ['.id' => $id, 'name' => $name, 'type' => 'ether'];
            $tables['interface/ethernet'][] = ['.id' => $id, 'name' => $name, 'default-name' => $factory];
        }
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $preview = route('manufacturers.models.mikrotik-ports', [$model->manufacturer_id, $model]);
        $this->postJson($preview, ['ports' => $ports])->assertOk()->assertJsonPath('observed', true)
            ->assertJsonPath('matches.first', 'ether1')->assertJsonPath('matches.second', 'ether2')
            ->assertJsonPath('matches.optical', 'sfp-sfpplus1');
        $ports[0]['mikrotikName'] = 'ether2';
        $ports[1]['mikrotikName'] = 'ether1';
        $ports[2]['mikrotikName'] = null;
        $this->postJson($preview, ['ports' => $ports])->assertOk()
            ->assertJsonPath('matches.first', 'ether2')->assertJsonPath('matches.second', 'ether1')->assertJsonMissingPath('matches.optical');
        $model->update(['panel' => ['faces' => ['front' => ['ports' => $ports], 'rear' => ['ports' => []]]]]);
        $this->post($this->url('sync'), $this->operation())->assertSessionHasNoErrors();
        $interfaces = $this->device->interfaces()->get()->keyBy('connector_id');
        $this->assertSame('ether2', $interfaces['first']->mikrotik['default_name']);
        $this->assertSame('ether1', $interfaces['second']->mikrotik['default_name']);
        $this->assertNull($interfaces['optical']->mikrotik);
        $this->assertSame('1', $model->fresh()->panel['faces']['front']['ports'][0]['label']);
        $this->actingAs(User::factory()->create(['is_superadmin' => false]))
            ->postJson($preview, ['ports' => $ports])->assertForbidden();
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_saving_numbered_ports_applies_existing_observations_without_another_router_read(): void
    {
        $port = ['id' => str_repeat('a', 32), 'type' => 'rj45', 'label' => '',
            'x' => 50, 'y' => 50, 'rotation' => 0, 'scale' => 1, 'labelPosition' => 'center',
            'poe' => false, 'group' => null, 'opacity' => 1, 'opacityCustomized' => false,
            'borderColor' => '#000000', 'hidden' => false, 'locked' => false];
        $face = ['ports' => [], 'image' => null, 'order' => [], 'shellState' => ['hidden' => false, 'locked' => false]];
        $panel = ['version' => 3, 'name' => 'Router',
            'shell' => ['x' => 0, 'y' => 0, 'width' => 900, 'height' => 180, 'radius' => 8, 'border' => 2],
            'faces' => ['front' => [...$face, 'ports' => [$port, [...$port, 'id' => str_repeat('b', 32)]]], 'rear' => $face],
            'numbering' => ['next' => 1, 'prefix' => '']];
        $model = DeviceModel::create(['name' => 'Synthetic model', 'manufacturer_id' => $this->device->manufacturer_id, 'panel' => $panel]);
        $this->device->update(['device_model_id' => $model->id]);
        $this->device->addModelInterfaces($model);
        $tables = $this->tables();
        $tables['interface/ethernet'][0]['mac-address'] = '02:00:00:00:00:01';
        $this->fake($tables);
        $this->post($this->url('activate'), $this->operation())->assertSessionHasNoErrors();
        $source = $this->device->mikrotikSources()->where('kind', 'interface')->sole();
        $this->assertNull($source->device_interface_id);
        $seenAt = $source->seen_at;
        $this->fake($tables);
        $panel['faces']['front']['ports'][0]['label'] = '1';
        $url = route('manufacturers.models.panel.update', [$model->manufacturer_id, $model]);
        $this->postJson($url, ['document' => json_encode($panel), 'revision' => 0])->assertOk();
        $interface = $this->device->interfaces()->where('connector_id', $port['id'])->sole();
        $this->assertSame('ether1', $interface->mikrotik['name']);
        $this->assertSame('02:00:00:00:00:01', $interface->mikrotik['mac_address']);
        $this->assertNull($interface->name);
        $this->assertNull($interface->mac_address);
        $this->assertTrue($source->fresh()->interface_from_model);
        $this->assertTrue($seenAt->equalTo($source->fresh()->seen_at));
        $panel['faces']['front']['ports'][0]['mikrotikName'] = null;
        $this->postJson($url, ['document' => json_encode($panel), 'revision' => 1])->assertOk();
        $this->assertNull($source->fresh()->device_interface_id);
        Http::assertNothingSent();
    }

    private function tables(): array
    {
        $tables = json_decode(file_get_contents(base_path('tests/Fixtures/mikrotik/synthetic.json')), true, flags: JSON_THROW_ON_ERROR)['tables'];
        $tables['interface'] = [['.id' => '*1', 'name' => 'ether1', 'type' => 'ether'], ['.id' => '*2', 'name' => 'vlan10', 'type' => 'vlan'], ['.id' => '*3', 'name' => 'vlan20', 'type' => 'vlan']];
        $tables['interface/ethernet'] = [['.id' => '*1', 'name' => 'ether1', 'default-name' => 'ether1']];
        $tables['interface/vlan'] = [
            ['.id' => '*2', 'name' => 'vlan10', 'interface' => 'ether1', 'vlan-id' => '10', 'use-service-tag' => 'false', 'disabled' => 'false'],
            ['.id' => '*3', 'name' => 'vlan20', 'interface' => 'ether1', 'vlan-id' => '20', 'use-service-tag' => 'false', 'disabled' => 'false'],
        ];
        foreach (['interface/bridge', 'interface/bridge/port', 'interface/bridge/vlan', 'interface/bridge/host', 'interface/list', 'interface/list/member'] as $menu) {
            $tables[$menu] = [];
        }
        $tables['ip/address'] = [['.id' => '*1', 'address' => '192.0.2.1/29', 'interface' => 'vlan10', 'dynamic' => 'false', 'disabled' => 'false', 'invalid' => 'false', 'comment' => 'Source address comment']];
        $tables['ip/dhcp-server'] = [['.id' => '*1', 'name' => 'dhcp', 'interface' => 'vlan10', 'address-pool' => 'pool', 'disabled' => 'false', 'invalid' => 'false']];
        $tables['ip/dhcp-server/network'] = [['.id' => '*1', 'address' => '192.0.2.0/29', 'gateway' => '192.0.2.1']];
        $tables['ip/dhcp-server/lease'] = [['.id' => '*1', 'address' => '192.0.2.3', 'mac-address' => '02:00:00:00:00:03', 'server' => 'dhcp', 'dynamic' => 'false', 'disabled' => 'false', 'comment' => 'Source lease comment']];
        $tables['ip/pool'] = [['.id' => '*1', 'name' => 'pool', 'ranges' => '192.0.2.2-192.0.2.6']];
        $tables['ip/arp'] = [['.id' => '*1', 'address' => '192.0.2.2', 'mac-address' => '02:00:00:00:00:02', 'interface' => 'vlan10']];

        return $tables;
    }

    private function fake(array $tables, ?string $failedMenu = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://10.2.0.1/rest/*' => function (Request $request) use ($tables, $failedMenu) {
            $menu = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/rest/'));

            return $menu === $failedMenu ? Http::response([], 404) : Http::response($tables[$menu]);
        }]);
    }

    private function url(string $action): string
    {
        return route('companies.sites.devices.mikrotik.'.$action, [$this->device->site->company, $this->device->site, $this->device]);
    }

    private function operation(): array
    {
        return ['revision' => $this->device->mikrotikConnection()->first()?->revision, 'confirmed' => true];
    }
}

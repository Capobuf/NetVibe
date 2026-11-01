<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Device;
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
    }

    public function test_activation_imports_only_explicit_data_and_refresh_preserves_manual_records(): void
    {
        $tables = $this->tables();
        $this->fake($tables);
        $this->artisan('mikrotik:sync')->assertSuccessful();
        Http::assertNothingSent();
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
        $this->get($url.'?search=host&sort=hostname&direction=desc&page=2')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 55)->has('ipAddresses.data', 5)
            ->where('ipAddresses.data.0.ip', '192.0.2.7')->where('ipAddresses.data.4.ip', '192.0.2.2'));
        $this->get($url.'?search=host&sort=hostname')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 55)->where('ipAddresses.data.0.ip', '192.0.2.2'));
        $this->get($url.'?search=host&sort=mac_address&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 55)->where('ipAddresses.data.0.mac_address', '02:00:00:00:00:FF')
            ->where('ipAddresses.data.1.ip', '192.0.2.56'));
        $this->assertDatabaseCount('ip_addresses', 2);
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

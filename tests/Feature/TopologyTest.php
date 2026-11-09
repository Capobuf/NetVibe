<?php

namespace Tests\Feature;

use App\Models\Cable;
use App\Models\Company;
use App\Models\Device;
use App\Models\Manufacturer;
use App\Models\MikrotikSource;
use App\Models\Site;
use App\Models\User;
use App\Support\MikrotikClient;
use App\Support\MikrotikSync;
use App\Support\Topology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TopologyTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Device $local;

    private Device $remote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->site = Company::create(['name' => 'Alfa'])->sites()->create(['name' => 'Torino']);
        $vendor = Manufacturer::create(['name' => 'MikroTik']);
        $model = $vendor->models()->create(['name' => 'Switch', 'panel' => ['faces' => [
            'front' => ['ports' => [['id' => str_repeat('a', 32), 'label' => '1', 'type' => 'rj45']]],
            'rear' => ['ports' => []],
        ]]]);
        foreach (['local' => '02:00:00:00:00:01', 'remote' => '02:00:00:00:00:02'] as $name => $mac) {
            $device = $this->site->devices()->create(['name' => $name, 'manufacturer_id' => $vendor->id, 'device_model_id' => $model->id]);
            $device->interfaces()->create(['kind' => 'physical', 'name' => 'ether1', 'mac_address' => $mac, 'connector_id' => str_repeat('a', 32)]);
            $device->mikrotikConnection()->create(['server_url' => 'https://10.2.0.1', 'username' => 'reader', 'password' => 'topology-secret',
                'revision' => (string) Str::uuid(), 'source_generation' => (string) Str::uuid(), 'activated_at' => now(), 'synced_at' => now(), 'sync_status' => 'ok']);
            $this->{$name} = $device;
        }
    }

    private function neighbor(Device $from, Device $to, array $changes = []): MikrotikSource
    {
        $source = $from->mikrotikSources()->make([
            'generation' => $from->mikrotikConnection->source_generation, 'kind' => 'observation',
            'source_key' => 'ip/neighbor:*1', 'state' => 'current', 'seen_at' => now(), 'anchor' => [],
            'data' => [...[
                'menu' => '/ip/neighbor', 'interface' => 'ether1,bridge', 'identity' => $to->name,
                'mac' => $to->interfaces()->sole()->mac_address, 'discovered-by' => 'lldp', 'lldp_read' => true,
                'lldp_seen_at' => now()->toIso8601String(),
                'lldp' => ['lldp-port-id' => 'ether1', 'lldp-port-id-subtype' => 'interface-name', 'lldp-system-name' => $to->name],
            ], ...$changes],
        ]);
        $source->site_id = $from->site_id;
        $source->save();

        return $source;
    }

    public function test_inventory_matching_requires_agreeing_identity_and_mac_in_the_same_site(): void
    {
        Http::preventStrayRequests();
        $source = $this->neighbor($this->local, $this->remote);
        $url = route('companies.sites.topology.index', [$this->site->company, $this->site]);
        $this->get($url)->assertOk()->assertDontSee('topology-secret')
            ->assertInertia(fn (Assert $page) => $page->component('topology/index')->has('topology.nodes', 2)
                ->has('topology.models', 1)
                ->where('topology.nodes.0.model_id', $this->local->device_model_id)
                ->where('topology.models.'.$this->local->device_model_id.'.panel', $this->local->model->panel)
                ->where('topology.edges.0.kind', 'discovered')->where('topology.edges.0.reciprocal', false));
        $this->neighbor($this->remote, $this->local);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('topology.edges', 1)->where('topology.edges.0.reciprocal', true));
        $source->update(['data' => [...$source->data, 'lldp' => null]]);
        $partial = collect((new Topology)->forSite($this->site)['edges'])->firstWhere('kind', 'partial');
        $this->assertNull($partial['b']);
        $this->assertNull($partial['suggestion']);
        $source->update(['data' => [...$source->data, 'identity' => 'Unknown', 'lldp' => null]]);
        // A MAC documented on exactly one inventory interface identifies the device without its name.
        $this->assertCount(2, (new Topology)->forSite($this->site)['nodes']);
        $this->remote->interfaces()->update(['mac_address' => null]);
        $graph = (new Topology)->forSite($this->site);
        $this->assertCount(3, $graph['nodes']);
        $this->assertCount(1, array_filter($graph['edges'], fn ($edge) => $edge['kind'] === 'partial'));
        $this->assertDatabaseCount('devices', 2);
        Http::assertNothingSent();
    }

    public function test_nic_lldp_matches_a_mac_documented_on_one_interface_and_only_a_port_mac_resolves_the_port(): void
    {
        $mac = $this->remote->interfaces()->sole()->mac_address;
        $source = $this->neighbor($this->local, $this->remote, ['identity' => 'Broadcom NIC', 'lldp' => [
            'lldp-system-name' => 'Broadcom NIC', 'lldp-chassis-id' => $mac, 'lldp-chassis-id-subtype' => 'mac-address',
            'lldp-port-id' => $mac, 'lldp-port-id-subtype' => 'mac-address',
        ]]);
        $edge = (new Topology)->forSite($this->site)['edges'][0];
        $this->assertSame('device:'.$this->remote->id, $edge['target']);
        $this->assertSame('interface_mac', $edge['evidence'][0]['inventory_match']);
        $this->assertSame('discovered', $edge['kind']);
        $this->assertSame(str_repeat('a', 32), $edge['b']['connector_id']);
        // A chassis MAC may belong to another port: the device is known, the cabled port is not.
        $source->update(['data' => [...$source->data, 'lldp' => [...$source->data['lldp'], 'lldp-port-id' => 'x', 'lldp-port-id-subtype' => 'local']]]);
        $edge = (new Topology)->forSite($this->site)['edges'][0];
        $this->assertSame('device:'.$this->remote->id, $edge['target']);
        $this->assertSame('partial', $edge['kind']);
        $this->assertNull($edge['b']);
    }

    public function test_neighbor_without_inventory_mac_matches_only_a_unique_exact_name_and_model_in_its_site(): void
    {
        $this->remote->interfaces()->update(['mac_address' => null]);
        $this->neighbor($this->local, $this->remote, ['mac' => '02:00:00:00:00:02', 'board' => 'Switch']);
        $foreign = Company::create(['name' => 'Other'])->sites()->create(['name' => 'Other']);
        $attributes = ['name' => $this->remote->name, 'manufacturer_id' => $this->remote->manufacturer_id,
            'device_model_id' => $this->remote->device_model_id];
        $foreign->devices()->create($attributes);
        $graph = (new Topology)->forSite($this->site);
        $this->assertCount(2, $graph['nodes']);
        $this->assertSame('device:'.$this->remote->id, $graph['edges'][0]['target']);
        $this->assertSame('name_model', $graph['edges'][0]['evidence'][0]['inventory_match']);
        $this->assertNull($this->remote->interfaces()->sole()->mac_address);
        $duplicate = $this->site->devices()->create($attributes);
        $this->assertStringStartsWith('neighbor:', (new Topology)->forSite($this->site)['edges'][0]['target']);
        $duplicate->delete();
        $this->remote->interfaces()->update(['mac_address' => '02:00:00:00:00:99']);
        $this->assertStringStartsWith('neighbor:', (new Topology)->forSite($this->site)['edges'][0]['target']);
    }

    public function test_remote_port_uses_the_saved_model_mapping_without_a_remote_integration(): void
    {
        $this->neighbor($this->local, $this->remote);
        $this->remote->mikrotikConnection()->delete();
        $this->remote->interfaces()->update(['name' => null]);
        $model = $this->remote->model;
        $panel = $model->panel;
        $panel['faces']['front']['ports'][0]['mikrotikName'] = 'ether1';
        $model->update(['panel' => $panel]);
        $url = route('companies.sites.topology.index', [$this->site->company, $this->site]);

        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('topology.edges', 1)
            ->where('topology.edges.0.kind', 'discovered')
            ->where('topology.edges.0.b.connector_id', str_repeat('a', 32))
            ->where('topology.edges.0.b.label', '1'));
        $this->assertNull($this->remote->interfaces()->sole()->name);
        $this->assertDatabaseCount('cables', 0);

        $panel['faces']['front']['ports'][] = [...$panel['faces']['front']['ports'][0], 'id' => str_repeat('b', 32)];
        $model->update(['panel' => $panel]);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('topology.edges.0.b', null));

        array_pop($panel['faces']['front']['ports']);
        $panel['faces']['front']['ports'][0]['mikrotikName'] = null;
        $model->update(['panel' => $panel]);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('topology.edges.0.b', null));

        unset($panel['faces']['front']['ports'][0]['mikrotikName']);
        $panel['faces']['front']['ports'][0]['type'] = 'sfp';
        $panel['faces']['front']['ports'][0]['label'] = 'sfp+1';
        $model->update(['panel' => $panel]);
        $source = $this->local->mikrotikSources()->sole();
        $source->update(['data' => [...$source->data,
            'lldp' => [...$source->data['lldp'], 'lldp-port-id' => 'sfp-sfpplus1'],
        ]]);
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('topology.edges.0.b.connector_id', str_repeat('a', 32))
            ->where('topology.edges.0.b.label', 'sfp+1'));
    }

    public function test_suggestion_is_rechecked_in_the_rack_and_never_creates_or_replaces_a_cable_on_get(): void
    {
        $this->neighbor($this->local, $this->remote);
        $rack = $this->site->racks()->create(['name' => 'Rack A', 'height_u' => 24, 'numbering' => 'bottom-up']);
        $rack->items()->create(['key' => str_repeat('b', 32), 'kind' => 'device', 'device_id' => $this->local->id, 'position' => 0, 'height' => 1, 'width' => 100, 'x' => 0]);
        $edge = (new Topology)->forSite($this->site)['edges'][0];
        $this->assertSame($rack->id, $edge['suggestion']['rack_id']);
        $url = route('companies.sites.racks.show', [$this->site->company, $this->site, $rack, 'topology_edge' => $edge['id']]);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('topologySuggestion.a.device_id', $this->local->id)->where('topologySuggestion.b.device_id', $this->remote->id));
        $this->assertDatabaseCount('cables', 0);
        $cable = Cable::create(['site_id' => $this->site->id, 'type' => 'copper', 'color' => '#2563eb']);
        $cable->ends()->createMany([
            ['side' => 'a', ...$edge['suggestion']['a']], ['side' => 'b', ...$edge['suggestion']['b']],
        ]);
        $this->local->mikrotikConnection()->update(['sync_status' => 'network']);
        $graph = (new Topology)->forSite($this->site);
        $this->assertCount(1, $graph['edges']);
        $this->assertSame('documented', $graph['edges'][0]['kind']);
        $this->assertTrue($graph['edges'][0]['evidence'][0]['stale']);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('topologySuggestion', null)->whereNot('topologyNotice', null));
        $this->assertDatabaseCount('cables', 1);
    }

    public function test_vlan_observations_do_not_draw_physical_links_but_mixed_physical_observations_remain(): void
    {
        $vlan = $this->local->mikrotikSources()->make([
            'generation' => $this->local->mikrotikConnection->source_generation,
            'kind' => 'vlan', 'source_key' => 'interface/vlan:*1', 'state' => 'current',
            'seen_at' => now(), 'anchor' => [],
            'data' => ['menu' => '/interface/vlan', 'name' => 'Management', 'tag' => 100],
        ]);
        $vlan->site_id = $this->site->id;
        $vlan->save();
        $bridgeVlan = $vlan->replicate();
        $bridgeVlan->fill([
            'source_key' => 'interface/bridge/vlan:*1',
            'data' => ['menu' => '/interface/bridge/vlan', 'name' => 'ether1', 'tag' => 100],
        ])->save();
        $source = $this->neighbor($this->local, $this->remote, ['interface' => 'Management']);
        $url = route('companies.sites.topology.index', [$this->site->company, $this->site]);

        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('topology.edges', 0)->has('topology.nodes', 2));

        $source->update(['data' => [...$source->data, 'interface' => 'ether1,Management']]);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('topology.edges', 1)
            ->where('topology.edges.0.kind', 'discovered')
            ->where('topology.edges.0.evidence.0.local_port', 'ether1'));

        $this->remote->interfaces()->create(['kind' => 'logical', 'name' => 'Management']);
        $source->update(['data' => [...$source->data,
            'lldp' => [...$source->data['lldp'], 'lldp-port-id' => 'Management'],
        ]]);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('topology.edges', 0));
        $this->assertDatabaseCount('mikrotik_sources', 3);
    }

    public function test_optional_lldp_failure_keeps_details_but_cannot_offer_a_fresh_suggestion(): void
    {
        $this->freezeTime();
        $tables = array_fill_keys(array_keys(MikrotikClient::MENUS), []);
        $tables['system/identity'] = [['name' => 'local']];
        $tables['system/resource'] = [['version' => '7.25', 'board-name' => 'Synthetic', 'architecture-name' => 'arm64']];
        $tables['ip/neighbor'] = [['.id' => '*1', 'interface' => 'ether1', 'identity' => 'remote', 'mac-address' => '02:00:00:00:00:02', 'discovered-by' => 'lldp']];
        $tables[MikrotikClient::LLDP_MENU] = [['interface' => 'ether1', 'mac-address' => '02:00:00:00:00:02', 'lldp-port-id' => 'ether1', 'lldp-port-id-subtype' => 'interface-name', 'lldp-system-name' => 'remote']];
        Http::preventStrayRequests();
        Http::fake(['https://10.2.0.1/rest/*' => function (Request $request) use (&$tables) {
            $menu = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/rest/'));

            return isset($tables[$menu]) ? Http::response($tables[$menu]) : Http::response([], 404);
        }]);
        (new MikrotikSync)->refresh($this->local->mikrotikConnection);
        $source = $this->local->mikrotikSources()->where('data->menu', '/ip/neighbor')->sole();
        $seen = $source->data['lldp_seen_at'];
        unset($tables[MikrotikClient::LLDP_MENU]);
        $this->travel(61)->seconds();
        (new MikrotikSync)->refresh($this->local->mikrotikConnection->fresh());
        $this->assertSame('ok', $this->local->mikrotikConnection->fresh()->sync_status);
        $this->assertSame($seen, $source->fresh()->data['lldp_seen_at']);
        $edge = (new Topology)->forSite($this->site)['edges'][0];
        $this->assertTrue($edge['stale']);
        $this->assertNull($edge['suggestion']);
        $tables['ip/neighbor'] = [];
        $this->travel(61)->seconds();
        (new MikrotikSync)->refresh($this->local->mikrotikConnection->fresh());
        $this->assertSame('missing', $source->fresh()->state);
        $this->assertCount(1, (new Topology)->forSite($this->site)['edges']);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_discovery_reports_an_occupied_termination_without_changing_documentation(): void
    {
        $this->neighbor($this->local, $this->remote);
        $patchPanel = $this->site->devices()->create(['name' => 'Patch panel', 'manufacturer_id' => $this->local->manufacturer_id,
            'device_model_id' => $this->local->device_model_id]);
        $cable = Cable::create(['site_id' => $this->site->id, 'type' => 'copper', 'color' => '#2563eb']);
        $cable->ends()->createMany([
            ['side' => 'a', 'device_id' => $this->local->id, 'connector_id' => str_repeat('a', 32)],
            ['side' => 'b', 'device_id' => $patchPanel->id, 'connector_id' => str_repeat('a', 32)],
        ]);
        $edge = collect((new Topology)->forSite($this->site)['edges'])->firstWhere('kind', 'discovered');
        $this->assertNotEmpty($edge['issues']);
        $this->assertSame([$cable->id], $edge['cable_ids']);
        $this->assertNull($edge['suggestion']);
        $this->assertSame($patchPanel->id, $cable->ends()->where('side', 'b')->sole()->device_id);
        $this->assertDatabaseCount('cables', 1);
    }

    public function test_access_and_neighbor_correlation_are_scoped_and_indirect_observations_do_not_create_edges(): void
    {
        $foreign = Company::create(['name' => 'Beta'])->sites()->create(['name' => 'Milano']);
        $foreignDevice = $foreign->devices()->create(['name' => 'Foreign', 'manufacturer_id' => $this->local->manufacturer_id]);
        $foreignDevice->interfaces()->create(['name' => 'ether1', 'kind' => 'physical', 'mac_address' => '02:00:00:00:00:03']);
        $this->neighbor($this->local, $foreignDevice);
        $graph = (new Topology)->forSite($this->site);
        $this->assertNull($graph['nodes'][2]['device_id']);
        $this->assertSame('partial', $graph['edges'][0]['kind']);
        $source = $this->local->mikrotikSources()->sole();
        $source->update(['data' => [...$source->data, 'menu' => '/ip/arp']]);
        $this->assertSame([], (new Topology)->forSite($this->site)['edges']);
        $member = User::factory()->create();
        $this->site->company->users()->attach($member);
        $rack = $this->site->racks()->create(['name' => 'Rack A', 'height_u' => 24, 'numbering' => 'bottom-up']);
        $rack->items()->create(['key' => str_repeat('c', 32), 'kind' => 'device', 'device_id' => $this->local->id,
            'position' => 8, 'height' => 1, 'width' => 100, 'x' => 0]);
        $foreign->racks()->create(['name' => 'Foreign rack', 'height_u' => 42, 'numbering' => 'bottom-up']);
        $this->actingAs($member)->get(route('companies.sites.topology.index', [$this->site->company, $this->site]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('racks', 1)->where('racks.0.id', $rack->id)
                ->where('racks.0.height_u', 24)->where('racks.0.numbering', 'bottom-up')
                ->has('racks.0.items', 1)->where('racks.0.items.0.device_id', $this->local->id)
                ->where('racks.0.items.0.position', 8));
        $this->get(route('companies.sites.topology.index', [$this->site->company, $foreign]))->assertNotFound();
        $this->get(route('companies.sites.topology.index', [$foreign->company, $foreign]))->assertForbidden();
    }

    public function test_indirect_summaries_preserve_counts_latest_sighting_and_generation_rules(): void
    {
        $generation = $this->local->mikrotikConnection->source_generation;
        $previous = (string) Str::uuid();
        foreach ([
            [$this->local, $generation, '/ip/arp', '2026-01-01 10:00:00'],
            [$this->local, $generation, '/ip/arp', '2026-01-01 11:00:00'],
            [$this->local, $previous, '/ip/arp', '2026-01-01 12:00:00'],
            [$this->local, $generation, '/ip/dhcp-server/lease', '2026-01-01 09:00:00'],
            [$this->remote, $this->remote->mikrotikConnection->source_generation, '/interface/bridge/host', '2026-01-01 08:00:00'],
        ] as $index => [$device, $sourceGeneration, $menu, $seen]) {
            $source = $device->mikrotikSources()->make([
                'generation' => $sourceGeneration, 'kind' => 'observation', 'source_key' => 'indirect:'.$index,
                'state' => 'current', 'seen_at' => $seen, 'anchor' => [], 'data' => ['menu' => $menu],
            ]);
            $source->site_id = $this->site->id;
            $source->save();
        }
        $url = route('companies.sites.topology.index', [$this->site->company, $this->site]);

        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->has('topology.edges', 0)->has('topology.nodes.0.indirect', 2)
            ->where('topology.nodes.0.indirect.0.menu', '/ip/arp')
            ->where('topology.nodes.0.indirect.0.count', 2)
            ->where('topology.nodes.0.indirect.0.seen_at', '2026-01-01T11:00:00+00:00')
            ->where('topology.nodes.0.indirect.1.count', 1)
            ->has('topology.nodes.1.indirect', 1)->where('topology.nodes.1.indirect.0.count', 1));

        $this->local->mikrotikConnection()->update(['activated_at' => null]);
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('topology.nodes.0.indirect.0.count', 3)
            ->where('topology.nodes.0.indirect.0.seen_at', '2026-01-01T12:00:00+00:00'));
    }
}

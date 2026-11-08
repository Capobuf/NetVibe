<?php

namespace Tests\Feature;

use App\Exceptions\UnifiException;
use App\Models\Company;
use App\Models\Manufacturer;
use App\Models\UnifiConnection;
use App\Models\UnifiDevice;
use App\Models\UnifiObservation;
use App\Models\UnifiSite;
use App\Models\User;
use App\Support\Ipv4Cidr;
use App\Support\Topology;
use App\Support\UnifiIpam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnifiIpamTest extends TestCase
{
    use RefreshDatabase;

    public function test_network_import_reuses_site_documentation_and_documents_connected_clients(): void
    {
        Http::preventStrayRequests();
        $source = $this->source();
        $site = $source->site;
        $vlan = $site->vlans()->create(['tag' => 10, 'name' => 'Manuale']);
        $subnet = $site->subnets()->create([...Ipv4Cidr::parse('192.0.2.0/29'), 'vlan_id' => $vlan->id, 'name' => 'Rete manuale']);
        $subnet->ipAddresses()->create(['address' => ip2long('192.0.2.1'), 'description' => 'Gateway MikroTik']);
        $network = ['id' => 'network-10', 'name' => 'Ufficio', 'management' => 'GATEWAY', 'vlanId' => 10, 'default' => false,
            'ipv4Configuration' => ['hostIpAddress' => '192.0.2.1', 'prefixLength' => 29,
                'dhcpConfiguration' => ['mode' => 'SERVER', 'ipAddressRange' => ['start' => '192.0.2.2', 'stop' => '192.0.2.6']]]];
        $client = ['id' => 'client-1', 'name' => 'Telefono Wi-Fi', 'type' => 'WIRELESS', 'ipAddress' => '192.0.2.2', 'macAddress' => '02:11:22:33:44:55'];
        $capture = fn () => DB::transaction(fn () => (new UnifiIpam)->capture($source, [$network,
            ['id' => 'l2', 'name' => 'L2', 'management' => 'UNMANAGED', 'vlanId' => 20, 'default' => false]], [$client]));
        $capture();
        $capture();

        $this->assertDatabaseCount('subnets', 1);
        $this->assertDatabaseCount('vlans', 2);
        $this->assertDatabaseCount('ip_addresses', 2);
        $this->assertDatabaseCount('devices', 0);
        $client = $subnet->ipAddresses()->where('address', ip2long('192.0.2.2'))->sole();
        $this->assertSame(['Telefono Wi-Fi', '02:11:22:33:44:55', true, null], [$client->hostname, $client->mac_address, $client->auto_managed, $client->device_id]);
        $this->assertSame('Gateway MikroTik', $subnet->ipAddresses()->where('address', ip2long('192.0.2.1'))->value('description'));
        $this->assertSame('Manuale', $vlan->fresh()->name);
        $this->assertSame('Rete manuale', $subnet->fresh()->name);
        $this->assertSame('192.0.2.3', $subnet->firstProposableIp());
        $this->assertSame(['documented' => 2, 'excluded' => 0, 'external' => 0, 'proposable' => 4], $subnet->addressCounts());
        $this->assertSame($subnet->id, UnifiObservation::where('kind', 'network')->whereNotNull('subnet_id')->sole()->subnet_id);

        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->get(route('companies.sites.subnets.index', [$site->company_id, $site]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('unifiNetworks', 2)
            ->where('unifiNetworks.0.stale', false));
        $this->get(route('companies.sites.subnets.show', [$site->company_id, $site, $subnet, 'search' => 'Telefono', 'hide_free' => 1]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('ipAddresses.data', 1)->where('ipAddresses.data.0.ip', '192.0.2.2'));
        $this->get(route('companies.sites.search.index', [$site->company_id, $site, 'search' => 'Telefono']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('results.data', 1)->where('results.data.0.id', $client->id)
            ->where('results.data.0.ip', '192.0.2.2')->has('results.data.0.unifi_observations', 1));

        // An unreachable controller never frees what it last reported.
        $source->update(['sync_status' => 'unreachable']);
        $this->assertSame('192.0.2.3', $subnet->firstProposableIp());
        $this->assertTrue(UnifiIpam::forSubnet($subnet)->firstWhere('kind', 'client')['stale']);
        $this->get(route('companies.sites.subnets.show', [$site->company_id, $site, $subnet, 'search' => 'Telefono', 'hide_free' => 1]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('ipAddresses.data', 1));
        Http::assertNothingSent();
    }

    public function test_preview_does_not_import_and_malformed_or_changed_networks_cannot_replace_documentation(): void
    {
        Http::preventStrayRequests();
        $source = $this->source();
        $source->update(['inventory_activated_at' => null]);
        $network = ['id' => 'default', 'management' => 'GATEWAY', 'name' => 'Default', 'default' => true, 'vlanId' => 1,
            'ipv4Configuration' => ['hostIpAddress' => '198.51.100.1', 'prefixLength' => 24]];
        DB::transaction(fn () => (new UnifiIpam)->capture($source, [$network], []));
        $this->assertDatabaseCount('subnets', 0);
        $source->update(['inventory_activated_at' => now()]);
        DB::transaction(fn () => (new UnifiIpam)->activate($source));
        $this->assertDatabaseCount('subnets', 1);
        $this->assertDatabaseCount('vlans', 0);
        $configured = $source->site->subnets()->sole()->ipAddresses()->sole();
        $this->assertNull($configured->device_id);
        // The source justifies the record: no description is invented for it.
        $this->assertNull($configured->description);
        $this->assertTrue($configured->auto_managed);
        $this->assertSame(ip2long('198.51.100.1'), $configured->address);
        $network['ipv4Configuration']['hostIpAddress'] = '203.0.113.1';
        DB::transaction(fn () => (new UnifiIpam)->capture($source, [$network], []));
        DB::transaction(fn () => (new UnifiIpam)->capture($source, [$network], []));
        $this->assertDatabaseCount('subnets', 1);
        $this->assertSame('198.51.100.0/24', $source->site->subnets()->sole()->cidr);
        $this->assertNotNull(UnifiObservation::sole()->problem);
        $network['ipv4Configuration']['additionalHostIpSubnets'] = ['invalid'];
        try {
            DB::transaction(fn () => (new UnifiIpam)->capture($source, [$network], []));
            $this->fail('Malformed snapshot accepted.');
        } catch (UnifiException $exception) {
            $this->assertSame('format', $exception->status);
        }
        $this->assertSame('current', UnifiObservation::sole()->state);
        Http::assertNothingSent();
    }

    public function test_network_decisions_and_uplinks_cannot_cross_site_boundaries(): void
    {
        Http::preventStrayRequests();
        $source = $this->source();
        $site = $source->site;
        $other = Company::create(['name' => 'Altro cliente'])->sites()->create(['name' => 'Altra sede']);
        $manufacturer = Manufacturer::create(['name' => 'Ubiquiti']);
        $model = $manufacturer->models()->create(['name' => 'Synthetic model']);
        $catalog = ['manufacturer_id' => $manufacturer->id, 'device_model_id' => $model->id];
        $local = $site->devices()->create(['name' => 'AP', ...$catalog]);
        $parent = $site->devices()->create(['name' => 'Switch', ...$catalog]);
        $foreign = $other->devices()->create(['name' => 'Altro switch', ...$catalog]);
        $switchId = (string) Str::uuid();
        $source->unifiDevices()->create(['external_id' => $switchId, 'device_id' => $parent->id, 'status' => 'linked', 'present' => true,
            'observed' => ['name' => 'Switch'], 'synced_at' => now()]);
        $ap = $source->unifiDevices()->create(['external_id' => (string) Str::uuid(), 'device_id' => $local->id, 'status' => 'linked', 'present' => true,
            'observed' => ['name' => 'AP', 'uplink_device_id' => $switchId], 'synced_at' => now()]);
        $edges = (new Topology)->forSite($site)['edges'];
        $this->assertCount(1, $edges);
        $this->assertSame('logical', $edges[0]['kind']);
        $this->assertNull($edges[0]['a']);
        $this->assertNull($edges[0]['suggestion']);
        $this->assertDatabaseCount('cables', 0);
        UnifiDevice::where('external_id', $switchId)->update(['device_id' => $foreign->id]);
        $this->assertSame([], (new Topology)->forSite($site)['edges']);

        $observation = UnifiObservation::create(['unifi_site_id' => $source->id, 'site_id' => $site->id,
            'kind' => 'network', 'source_key' => 'vlan', 'state' => 'current', 'data' => ['name' => 'Observed'],
            'problem' => 'Nome VLAN diverso dalla fonte.', 'seen_at' => now()]);
        $this->actingAs(User::factory()->create(['is_superadmin' => true]))
            ->put(route('companies.sites.unifi.observations.update', [$other->company_id, $other, $observation]), ['decision' => 'keep'])
            ->assertNotFound();
        UnifiIpam::resetForMapping($source);
        $this->assertDatabaseCount('unifi_observations', 0);
        $this->assertTrue($local->exists);
        Http::assertNothingSent();
    }

    public function test_expected_mac_is_recognized_only_when_the_same_client_reports_it(): void
    {
        Http::preventStrayRequests();
        $source = $this->source();
        $subnet = $source->site->subnets()->create(Ipv4Cidr::parse('192.0.2.0/29'));
        $ip = $subnet->ipAddresses()->create(['address' => ip2long('192.0.2.2'), 'hostname' => 'Manuale', 'mac_address' => '02:11:22:33:44:55']);
        $client = ['id' => 'client-1', 'name' => 'Osservato', 'type' => 'WIRELESS', 'ipAddress' => '192.0.2.2', 'macAddress' => '02:11:22:33:44:55'];
        DB::transaction(fn () => (new UnifiIpam)->capture($source, [], [$client]));
        DB::transaction(function () use ($ip) {
            UnifiIpam::manualChange($ip, ['mac_address' => '02:11:22:33:44:66'], 'planned');
            $ip->update(['mac_address' => '02:11:22:33:44:66']);
        });
        DB::transaction(fn () => (new UnifiIpam)->capture($source, [], [$client]));
        $this->assertNull(UnifiObservation::sole()->matched_at);
        $client['macAddress'] = '02:11:22:33:44:66';
        DB::transaction(fn () => (new UnifiIpam)->capture($source, [], [$client]));
        $this->assertNotNull(UnifiObservation::sole()->matched_at);
        $this->assertSame('Manuale', $ip->fresh()->hostname);
        $this->assertSame('02:11:22:33:44:66', $ip->fresh()->mac_address);
        Http::assertNothingSent();
    }

    public function test_fixed_ip_reservations_document_offline_clients_until_they_are_removed(): void
    {
        Http::preventStrayRequests();
        $source = $this->source();
        $subnet = $source->site->subnets()->create(Ipv4Cidr::parse('192.0.2.0/29'));
        $reservation = ['id' => str_repeat('a', 24), 'mac' => '02:00:00:00:00:51', 'ip' => '192.0.2.5', 'name' => 'PRINT-1',
            'hostname' => 'XeroxC7025', 'dns' => 'print1.example.test', 'last_seen' => now()->subDays(40)->timestamp];
        $capture = fn (?array $reservations) => DB::transaction(fn () => (new UnifiIpam)->capture($source, null, [], [], $reservations));
        $capture([$reservation]);

        $ip = $subnet->ipAddresses()->sole();
        $this->assertSame(['192.0.2.5', 'XeroxC7025', 'PRINT-1', '02:00:00:00:00:51', true],
            [long2ip($ip->address), $ip->hostname, $ip->description, $ip->mac_address, $ip->auto_managed]);
        $this->assertSame(now()->subDays(40)->timestamp, UnifiObservation::sole()->present_at->timestamp);
        $this->assertSame('192.0.2.1', $subnet->firstProposableIp());
        // An unreadable reservation list keeps the last data instead of reporting the reservation as removed.
        $capture(null);
        $this->assertSame('unknown', UnifiObservation::sole()->state);
        $this->assertTrue($ip->exists());
        $capture([]);
        $this->assertSame('missing', UnifiObservation::sole()->state);
        $this->assertDatabaseCount('ip_addresses', 0);
    }

    private function source(): UnifiSite
    {
        $site = Company::create(['name' => 'Cliente'])->sites()->create(['name' => 'Sede']);
        $connection = UnifiConnection::create(['name' => 'Console', 'mode' => 'local', 'server_url' => 'https://unifi.example.test',
            'api_key' => 'synthetic-key', 'revision' => (string) Str::uuid(), 'enabled' => true, 'check_status' => 'ok']);

        return $connection->unifiSites()->create(['console_id' => 'console', 'console_name' => 'Console',
            'external_site_id' => (string) Str::uuid(), 'name' => 'Sede UniFi', 'version' => '11.0.97', 'discovered_at' => now(),
            'site_id' => $site->id, 'confirmed_at' => now(), 'available' => true, 'inventory_activated_at' => now(), 'sync_status' => 'ok']);
    }
}

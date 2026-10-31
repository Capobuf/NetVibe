<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Device;
use App\Models\Manufacturer;
use App\Models\MikrotikConnection;
use App\Models\SnipeItCompanyLink;
use App\Models\SnipeItConnection;
use App\Models\SnipeItModel;
use App\Models\SnipeItSetting;
use App\Models\SnipeItSync;
use App\Models\User;
use App\Support\Ipv4Cidr;
use App\Support\MikrotikClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MikrotikTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $company = Company::create(['name' => 'Synthetic company']);
        $site = $company->sites()->create(['name' => 'Synthetic site']);
        $manufacturer = Manufacturer::create(['name' => 'MikroTik']);
        $model = $manufacturer->models()->create(['name' => 'Any RouterOS model']);
        $this->device = $site->devices()->create(['name' => 'Synthetic device', 'manufacturer_id' => $manufacturer->id, 'device_model_id' => $model->id]);
    }

    public function test_management_requires_superadmin_and_scoped_company_site_device_binding(): void
    {
        $this->post($this->url('verify'))->assertRedirect(route('login'));
        $member = User::factory()->create(['is_superadmin' => false]);
        $this->device->site->company->users()->attach($member);
        $this->actingAs($member);
        $this->put($this->url('update'), $this->data())->assertForbidden();
        $this->post($this->url('verify'))->assertForbidden();
        $this->post($this->url('analyze'))->assertForbidden();
        $this->delete($this->url('destroy'))->assertForbidden();
        $this->admin();
        $otherCompany = Company::create(['name' => 'Other company']);
        $site = $otherCompany->sites()->create(['name' => 'Other site']);
        $this->put(route('companies.sites.devices.mikrotik.update', [$otherCompany, $site, $this->device]), $this->data())->assertNotFound();
        $this->put(route('companies.sites.devices.mikrotik.update', [$otherCompany, $this->device->site, $this->device]), $this->data())->assertNotFound();
        $this->assertDatabaseCount('mikrotik_connections', 0);
        Http::assertNothingSent();
    }

    public function test_manufacturer_controls_visibility_and_access_without_a_model_or_router_type(): void
    {
        $this->admin();
        $this->device->update(['device_model_id' => null]);
        $this->get($this->showUrl())->assertInertia(fn (Assert $page) => $page->where('isMikrotik', true));
        $this->put($this->url('update'), $this->data())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('mikrotik_connections', 1);
        $other = Manufacturer::create(['name' => 'Other vendor']);
        $foreign = $this->device->site->devices()->create(['name' => 'MikroTik in name only', 'manufacturer_id' => $other->id]);
        $this->get(route('companies.sites.devices.show', [$foreign->site->company, $foreign->site, $foreign]))
            ->assertInertia(fn (Assert $page) => $page->where('isMikrotik', false)->where('mikrotik', null));
        $this->put(route('companies.sites.devices.mikrotik.update', [$foreign->site->company, $foreign->site, $foreign]), $this->data())->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_encrypted_credentials_are_device_specific_and_never_exposed_or_sent_on_page_load(): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data())->assertSessionHasNoErrors();
        $connection = $this->device->mikrotikConnection()->sole();
        $cipher = DB::table('mikrotik_connections')->value('password');
        $this->assertNotSame('synthetic-secret', $cipher);
        $this->assertSame('synthetic-secret', Crypt::decryptString($cipher));
        $this->assertArrayNotHasKey('password', $connection->toArray());
        $this->get($this->showUrl())->assertDontSee('synthetic-secret')->assertInertia(fn (Assert $page) => $page
            ->where('mikrotik.has_password', true)->where('mikrotik.allow_unverified_certificate', false)->missing('mikrotik.password'));
        $this->put($this->url('update'), $this->data(['password' => '']))->assertSessionHasNoErrors();
        $this->assertSame($cipher, DB::table('mikrotik_connections')->value('password'));
        $sibling = $this->device->site->devices()->create(['name' => 'Sibling', 'manufacturer_id' => $this->device->manufacturer_id]);
        $this->get(route('companies.sites.devices.show', [$sibling->site->company, $sibling->site, $sibling]))
            ->assertInertia(fn (Assert $page) => $page->where('mikrotik', null));
        $member = User::factory()->create(['is_superadmin' => false]);
        $this->device->site->company->users()->attach($member);
        $this->actingAs($member)->get($this->showUrl())->assertInertia(fn (Assert $page) => $page->where('mikrotik', null));
        Http::assertNothingSent();
    }

    public function test_endpoint_changes_and_removal_preserve_documented_ips_and_device_deletion_cascades_credentials(): void
    {
        $this->admin();
        $subnet = $this->device->site->subnets()->create(Ipv4Cidr::parse('10.1.0.0/24'));
        $ip = $subnet->ipAddresses()->create(['address' => ip2long('10.1.0.10'), 'device_id' => $this->device->id]);
        $before = $ip->fresh()->getAttributes();
        $this->put($this->url('update'), $this->data())->assertSessionHasNoErrors();
        $stale = $this->data();
        $this->put($this->url('update'), $this->data(['server_url' => 'https://10.2.0.2', 'password' => '']))->assertSessionHasErrors('password');
        $this->put($this->url('update'), $this->data(['server_url' => 'https://10.2.0.2']))->assertSessionHasNoErrors();
        $this->put($this->url('update'), $stale)->assertSessionHasErrors('connection');
        $this->delete($this->url('destroy'), ['revision' => $stale['revision'], 'confirmed' => true])->assertSessionHasErrors('connection');
        $this->assertSame($before, $ip->fresh()->getAttributes());
        $this->delete($this->url('destroy'), ['revision' => $this->data()['revision'], 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('mikrotik_connections', 0);
        $this->assertSame($before, $ip->fresh()->getAttributes());
        $this->put($this->url('update'), $this->data())->assertSessionHasNoErrors();
        $params = [$this->device->site->company, $this->device->site, $this->device];
        $impact = $this->getJson(route('companies.sites.devices.deletion-impact', $params))->assertOk()->json();
        $this->delete(route('companies.sites.devices.destroy', $params), ['confirmed' => true, 'impact_token' => $impact['impact_token']])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('mikrotik_connections', 0);
        Http::assertNothingSent();
    }

    public function test_all_manual_manufacturer_changes_preserve_the_connection_and_return_an_actionable_error(): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data())->assertSessionHasNoErrors();
        $vendor = Manufacturer::create(['name' => 'Other vendor']);
        $model = $vendor->models()->create(['name' => 'Other model']);
        $this->patch(route('companies.sites.devices.update', [$this->device->site->company, $this->device->site, $this->device]), ['name' => 'Renamed', 'device_model_id' => $model->id])->assertSessionHasErrors('device_model_id');
        $this->patch(route('manufacturers.models.update', [$this->device->manufacturer, $this->device->model]), ['name' => 'Changed model', 'manufacturer_id' => $vendor->id])->assertSessionHasErrors('manufacturer_id');
        $this->patch(route('manufacturers.update', $this->device->manufacturer), ['name' => 'Changed vendor'])->assertSessionHasErrors('name');
        $this->assertSame('MikroTik', $this->device->fresh()->manufacturer->name);
        $this->assertSame('synthetic-secret', $this->device->mikrotikConnection()->sole()->password);
    }

    #[TestWith(['https://127.0.0.1'])]
    #[TestWith(['https://169.254.169.254'])]
    #[TestWith(['https://168.63.129.16'])]
    #[TestWith(['http://10.2.0.1'])]
    #[TestWith(['https://user:synthetic-secret@10.2.0.1/rest'])]
    public function test_unsafe_addresses_are_rejected_without_flashing_secrets(string $url): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data(['server_url' => $url]))
            ->assertSessionHasErrors('server_url')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.server_url');
        Http::assertNothingSent();
    }

    public function test_dns_results_are_validated_and_pinned_before_sending_basic_auth(): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data(['server_url' => 'https://router.example:8443']))->assertSessionHasNoErrors();
        $client = Mockery::mock(MikrotikClient::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $client->shouldReceive('resolve')->with('router.example')->once()->andReturn(['127.0.0.1']);
        $this->app->instance(MikrotikClient::class, $client);
        $this->post($this->url('verify'), ['revision' => $this->data()['revision']])->assertSessionHasNoErrors();
        $this->assertSame('unsafe_url', $this->device->mikrotikConnection()->sole()->check_status);
        Http::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_analysis_only_uses_fixed_gets_and_separates_empty_and_unavailable_menus(bool $unverified): void
    {
        $this->withoutExceptionHandling();
        $this->admin();
        $this->put($this->url('update'), $this->data(['allow_unverified_certificate' => $unverified]))->assertSessionHasNoErrors();
        $tables = json_decode(file_get_contents(base_path('tests/Fixtures/mikrotik/synthetic.json')), true, flags: JSON_THROW_ON_ERROR)['tables'];
        $deviceBefore = $this->device->fresh()->getAttributes();
        Http::fake(['https://10.2.0.1/rest/*' => function (Request $request, array $options) use ($tables, $unverified) {
            $this->assertSame('GET', $request->method());
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(! $unverified, $options['verify']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame('v4', $options['force_ip_resolve']);
            $this->assertSame(['10.2.0.1:443:10.2.0.1'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertTrue($request->hasHeader('Authorization', 'Basic '.base64_encode('reader:synthetic-secret')));
            $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/rest/'));
            $this->assertArrayHasKey($path, $tables);
            $this->assertStringContainsString('.proplist=', $request->url());

            return $path === 'ip/neighbor' ? Http::response(['message' => 'synthetic-secret'], 404) : Http::response($tables[$path]);
        }]);
        $this->post($this->url('analyze'), ['revision' => $this->data()['revision']])->assertSessionHasNoErrors();
        $connection = $this->device->mikrotikConnection()->sole();
        $this->assertSame('partial', $connection->check_status);
        $coverage = collect($connection->analysis['coverage'])->keyBy('path');
        $this->assertSame('ok', $coverage['/ip/arp']['status']);
        $this->assertSame(0, $coverage['/ip/arp']['count']);
        $this->assertSame('not_found', $coverage['/ip/neighbor']['status']);
        $this->assertNull($coverage['/ip/neighbor']['count']);
        $this->assertSame($deviceBefore, $this->device->fresh()->getAttributes());
        foreach (['ip_addresses', 'subnets', 'vlans', 'device_interfaces', 'cables', 'racks'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->get($this->showUrl())->assertDontSee('synthetic-secret')->assertDontSee('192.0.2.10');
        Http::assertSentCount(18);
    }

    #[TestWith([401, 'unauthorized'])]
    #[TestWith([403, 'forbidden'])]
    #[TestWith([302, 'redirect'])]
    #[TestWith([404, 'not_found'])]
    #[TestWith([200, 'not_json'])]
    public function test_upstream_errors_are_controlled_and_stop_reading(int $code, string $status): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data())->assertSessionHasNoErrors();
        Http::fake(['https://10.2.0.1/rest/system/identity*' => Http::response('<html>synthetic-secret</html>', $code, ['Location' => 'https://127.0.0.1'])]);
        $this->post($this->url('verify'), ['revision' => $this->data()['revision']])->assertSessionHasNoErrors();
        $this->assertSame($status, $this->device->mikrotikConnection()->sole()->check_status);
        $this->get($this->showUrl())->assertDontSee('synthetic-secret');
        Http::assertSentCount(1);
    }

    public function test_an_oversized_menu_stops_the_analysis_without_retaining_its_payload(): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data())->assertSessionHasNoErrors();
        Http::fake([
            'https://10.2.0.1/rest/system/identity*' => Http::response(['name' => 'Synthetic router']),
            'https://10.2.0.1/rest/system/resource*' => Http::response(['version' => '7.17']),
            'https://10.2.0.1/rest/interface?*' => Http::response(str_repeat('x', 2_000_001)),
        ]);
        $this->post($this->url('analyze'), ['revision' => $this->data()['revision']])->assertSessionHasNoErrors();
        $connection = $this->device->mikrotikConnection()->sole();
        $this->assertSame('limit', $connection->check_status);
        $coverage = collect($connection->analysis['coverage'])->keyBy('path');
        $this->assertTrue($coverage['/interface']['attempted']);
        $this->assertNull($coverage['/interface']['count']);
        $this->assertFalse($coverage['/interface/ethernet']['attempted']);
        $this->assertSame('not_read', $coverage['/interface/ethernet']['status']);
        Http::assertSentCount(3);
    }

    public function test_changed_credentials_during_a_read_cannot_receive_stale_results(): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data())->assertSessionHasNoErrors();
        Http::fake([
            'https://10.2.0.1/rest/system/identity*' => function () {
                MikrotikConnection::query()->update(['revision' => (string) Str::uuid()]);

                return Http::response(['name' => 'Synthetic router']);
            },
            'https://10.2.0.1/rest/system/resource*' => Http::response(['version' => '7.17']),
        ]);
        $this->post($this->url('verify'), ['revision' => $this->data()['revision']])->assertSessionHasErrors('connection');
        $this->assertNull($this->device->mikrotikConnection()->sole()->checked_at);
    }

    public function test_snipe_it_cannot_hide_credentials_when_the_remote_manufacturer_changes(): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data())->assertSessionHasNoErrors();
        $company = $this->device->site->company;
        $connection = SnipeItConnection::create(['server_url' => 'https://10.2.0.3', 'api_key' => 'synthetic-token']);
        SnipeItSetting::findOrFail('global')->update(['connection_id' => $connection->id]);
        SnipeItSetting::forCompany($company)->save();
        SnipeItCompanyLink::create(['company_id' => $company->id, 'connection_id' => $connection->id, 'external_company_id' => 7, 'external_company_name' => 'Synthetic company', 'confirmed_at' => now()]);
        $sync = SnipeItSync::create(['company_id' => $company->id, 'connection_id' => $connection->id, 'external_company_id' => 7, 'activated_at' => now()]);
        $snapshot = ['name' => $this->device->model->name, 'manufacturer' => 'MikroTik', 'category' => null];
        $external = SnipeItModel::create(['connection_id' => $connection->id, 'external_id' => 1, 'device_model_id' => $this->device->device_model_id, 'snapshot' => $snapshot, 'applied' => $snapshot]);
        $sync->assets()->create(['external_id' => 1, 'snipe_it_model_id' => $external->id, 'device_id' => $this->device->id, 'state' => 'linked', 'present' => true, 'snapshot' => ['name' => $this->device->name, 'asset_tag' => null, 'serial' => null, 'model_id' => 1]]);
        Http::fake(['https://10.2.0.3/api/v1/hardware*' => Http::response(['total' => 1, 'rows' => [[
            'id' => 1, 'company' => ['id' => 7], 'name' => 'Remote rename', 'asset_tag' => null, 'serial' => null,
            'model' => ['id' => 1, 'name' => $snapshot['name']], 'manufacturer' => ['name' => 'Other vendor'], 'category' => null,
        ]]])]);
        app(\App\Support\SnipeItSync::class)->refresh($company);
        $this->assertSame('MikroTik', $this->device->fresh()->manufacturer->name);
        $this->assertSame('Remote rename', $this->device->fresh()->name);
        $this->assertNotEmpty($external->fresh()->problem);
        $this->assertSame('synthetic-secret', $this->device->mikrotikConnection()->sole()->password);
        Http::assertSentCount(1);
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
    }

    private function url(string $action): string
    {
        return route('companies.sites.devices.mikrotik.'.$action, [$this->device->site->company, $this->device->site, $this->device]);
    }

    private function showUrl(): string
    {
        return route('companies.sites.devices.show', [$this->device->site->company, $this->device->site, $this->device]);
    }

    private function data(array $overrides = []): array
    {
        return [...[
            'server_url' => 'https://10.2.0.1', 'username' => 'reader', 'password' => 'synthetic-secret',
            'allow_unverified_certificate' => false, 'revision' => $this->device->mikrotikConnection()->first()?->revision,
        ], ...$overrides];
    }
}

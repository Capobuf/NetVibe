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
use GuzzleHttp\TransferStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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

    public function test_setup_command_is_admin_only_scoped_and_does_not_save_credentials_or_contact_the_router(): void
    {
        $this->postJson($this->url('setup-command'), ['port' => 8443])->assertUnauthorized();
        $member = User::factory()->create(['is_superadmin' => false]);
        $this->device->site->company->users()->attach($member);
        $this->actingAs($member)->postJson($this->url('setup-command'), ['port' => 8443])->assertForbidden();
        $this->admin();
        $otherSite = $this->device->site->company->sites()->create(['name' => 'Other site']);
        $this->postJson(route('companies.sites.devices.mikrotik.setup-command', [$this->device->site->company, $otherSite, $this->device]), ['port' => 8443])->assertNotFound();
        $this->postJson($this->url('setup-command'), ['port' => '443; /user remove admin'])->assertJsonValidationErrors('port');
        Http::assertNothingSent();
        Http::fake(['https://api.ipify.org' => Http::response('8.8.4.4')]);
        $response = $this->postJson($this->url('setup-command'), ['port' => 8443])->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $data = $response->json();
        $this->assertSame('netvibe-rest-api', $data['username']);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{48}\z/', $data['password']);
        $this->assertStringContainsString('password="'.$data['password'].'"', $data['command']);
        $this->assertStringContainsString('policy=read,api,rest-api;', $data['command']);
        $this->assertStringContainsString('chain=input action=accept protocol=tcp dst-port=8443 src-address=8.8.4.4/32', $data['command']);
        $this->assertStringContainsString('place-before=$firstRule', $data['command']);
        $this->assertStringContainsString('disabled=no port=8443', $data['command']);
        $this->assertStringContainsString(':error "Utente netvibe-rest-api gia esistente', $data['command']);
        $this->assertStringNotContainsString('action=drop', $data['command']);
        $this->assertDatabaseCount('mikrotik_connections', 0);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.ipify.org' && ! $request->hasHeader('Authorization'));
    }

    #[TestWith(['192.168.1.1', 200])]
    #[TestWith(['not-an-ip', 200])]
    #[TestWith(['8.8.4.4', 503])]
    public function test_setup_command_requires_a_valid_public_ipv4(string $body, int $status): void
    {
        $this->admin();
        Http::fake(['https://api.ipify.org' => Http::response($body, $status)]);
        $this->postJson($this->url('setup-command'), ['port' => 443])->assertJsonValidationErrors('setup');
        $this->assertDatabaseCount('mikrotik_connections', 0);
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

    public function test_device_integrations_preserve_access_boundaries_and_operation_destinations(): void
    {
        $company = $this->device->site->company;
        $site = $this->device->site;
        $params = [$company, $site, $this->device];
        $url = route('companies.sites.devices.show', $params);
        $mikrotikUrl = route('companies.sites.devices.show', [...$params, 'manage' => 'mikrotik']);
        $this->get($url)->assertRedirect(route('login'));

        $this->admin();
        $this->put($this->url('update'), $this->data())->assertSessionHasNoErrors()->assertRedirect($mikrotikUrl);
        $this->get(route('companies.sites.devices.integrations', [...$params, 'tab' => 'mikrotik']))->assertRedirect($mikrotikUrl);
        $this->get($this->showUrl().'?tab=mikrotik')->assertInertia(fn (Assert $page) => $page
            ->where('initialTab', 'interfaces')->where('initialManagementTab', 'mikrotik'));
        $this->get($mikrotikUrl)->assertOk()->assertDontSee('synthetic-secret')
            ->assertInertia(fn (Assert $page) => $page
                ->component('devices/show')
                ->where('device.id', $this->device->id)
                ->where('initialManagementTab', 'mikrotik')
                ->where('mikrotik.username', 'reader')
                ->where('mikrotik.has_password', true)
                ->missing('mikrotik.password'));

        $member = User::factory()->create(['is_superadmin' => false]);
        $company->users()->attach($member);
        $this->actingAs($member)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('mikrotik', null)->where('mikrotikSources', []));
        $otherSite = $company->sites()->create(['name' => 'Other site']);
        $this->get(route('companies.sites.devices.show', [$company, $otherSite, $this->device]))->assertNotFound();
        $this->actingAs(User::factory()->create(['is_superadmin' => false]))->get($url)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_manual_sync_requires_successful_verification_of_current_settings(): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data())->assertSessionHasNoErrors();
        foreach (['activate', 'sync'] as $action) {
            $this->post($this->url($action), ['revision' => $this->data()['revision'], 'confirmed' => true])->assertSessionHasErrors('connection');
        }
        $this->assertNull($this->device->mikrotikConnection()->sole()->activated_at);
        Http::assertNothingSent();

        Http::fake(['https://10.2.0.1/rest/*' => Http::response([], 401)]);
        $this->post($this->url('verify'), ['revision' => $this->data()['revision']])->assertSessionHasNoErrors();
        foreach (['activate', 'sync'] as $action) {
            $this->post($this->url($action), ['revision' => $this->data()['revision'], 'confirmed' => true])->assertSessionHasErrors('connection');
        }
        Http::assertSentCount(1);

        $this->device->mikrotikConnection()->sole()->update(['check_status' => 'ok', 'checked_at' => now()]);
        $this->put($this->url('update'), $this->data(['username' => 'other-reader']))->assertSessionHasNoErrors();
        $this->assertNull($this->device->mikrotikConnection()->sole()->check_status);
        foreach (['activate', 'sync'] as $action) {
            $this->post($this->url($action), ['revision' => $this->data()['revision'], 'confirmed' => true])->assertSessionHasErrors('connection');
        }
        Http::assertSentCount(1);
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

    #[TestWith(['10.2.0.1', 'https://10.2.0.1'])]
    #[TestWith(['router.example', 'https://router.example'])]
    #[TestWith(['router.example:8443', 'https://router.example:8443'])]
    public function test_addresses_without_a_scheme_are_saved_as_https(string $input, string $expected): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data(['server_url' => $input]))->assertSessionHasNoErrors();
        $this->assertSame($expected, $this->device->mikrotikConnection()->sole()->server_url);
        $this->put($this->url('update'), $this->data(['server_url' => $input, 'password' => '']))->assertSessionHasNoErrors();
        $this->assertSame('synthetic-secret', $this->device->mikrotikConnection()->sole()->password);
        Http::assertNothingSent();
    }

    public function test_port_is_validated_and_independent_of_certificate_verification(): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data(['port' => 443]))->assertSessionHasNoErrors();
        $connection = $this->device->mikrotikConnection()->sole();
        $this->assertSame('https://10.2.0.1', $connection->server_url);
        $connection->update(['check_status' => 'ok', 'check_log' => 'Previous attempt']);
        $this->put($this->url('update'), $this->data(['port' => 8443]))->assertSessionHasNoErrors();
        $connection->refresh();
        $this->assertSame('https://10.2.0.1:8443', $connection->server_url);
        $this->assertNull($connection->check_status);
        $this->assertNull($connection->check_log);
        $this->put($this->url('update'), $this->data(['port' => 8443, 'allow_unverified_certificate' => true]))->assertSessionHasNoErrors();
        $this->assertSame('https://10.2.0.1:8443', $connection->fresh()->server_url);
        foreach (['', 0, 65536, 'abc', 443.5] as $port) {
            $this->put($this->url('update'), $this->data(['port' => $port]))->assertSessionHasErrors('port');
        }
        Http::assertNothingSent();
    }

    #[TestWith([35, 'tls'])]
    #[TestWith([28, 'timeout'])]
    #[TestWith([7, 'network'])]
    public function test_transport_errors_use_curl_stats_and_keep_only_sanitized_diagnostics(int $errno, string $status): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data())->assertSessionHasNoErrors();
        Http::fake(['https://10.2.0.1/rest/*' => function (Request $request, array $options) use ($errno) {
            fwrite($options['debug'], "* Trying 10.2.0.1:443...\n* transport detail synthetic-secret\n* Server auth using Basic with user 'reader'\n> Authorization: Basic ".base64_encode('reader:synthetic-secret')."\n< Set-Cookie: synthetic-cookie\n");
            $options['on_stats'](new TransferStats($request->toPsrRequest(), null, 0.1, $errno));
            throw new ConnectionException('Synthetic transport failure');
        }]);
        $this->post($this->url('verify'), ['revision' => $this->data()['revision']])->assertSessionHasNoErrors();
        $connection = $this->device->mikrotikConnection()->sole();
        $this->assertSame($status, $connection->check_status);
        $this->assertStringContainsString('GET https://10.2.0.1/rest/system/identity?', $connection->check_log);
        $this->assertStringContainsString('* Trying 10.2.0.1:443...', $connection->check_log);
        $this->assertStringContainsString('cURL '.$errno.':', $connection->check_log);
        $this->assertStringContainsString('* transport detail [omesso]', $connection->check_log);
        foreach (['synthetic-secret', 'synthetic-cookie', 'Authorization:', 'Server auth', base64_encode('reader:synthetic-secret')] as $secret) {
            $this->assertStringNotContainsString($secret, $connection->check_log);
        }
        $this->assertArrayNotHasKey('check_log', $connection->toArray());
        $this->get($this->showUrl())->assertInertia(fn (Assert $page) => $page->where('mikrotik.check_log', $connection->check_log));
    }

    #[TestWith([true, 35, true, 'ok', 3])]
    #[TestWith([false, 35, true, 'tls', 2])]
    #[TestWith([true, 35, false, 'tls', 3])]
    #[TestWith([true, 28, true, 'timeout', 1])]
    public function test_anonymous_tls_retries_only_an_opted_in_handshake_failure(bool $allow, int $errno, bool $respond, string $status, int $expectedAttempts): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data(['allow_unverified_certificate' => $allow]))->assertSessionHasNoErrors();
        $attempts = 0;
        Http::fake(['https://10.2.0.1/rest/*' => function (Request $request, array $options) use ($allow, $errno, $respond, &$attempts) {
            $attempts++;
            $anonymous = isset($options['curl'][CURLOPT_SSL_CIPHER_LIST]);
            $this->assertSame(! $allow, $options['verify']);
            $this->assertSame(['10.2.0.1:443:10.2.0.1'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertFalse($options['allow_redirects']);
            if ($attempts === 1) {
                $this->assertFalse($anonymous);
            }
            if ($anonymous) {
                $this->assertTrue($allow);
                $this->assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, $options['crypto_method']);
                $this->assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, $options['crypto_method_max']);
            }
            if (! $anonymous || ! $respond) {
                $options['on_stats'](new TransferStats($request->toPsrRequest(), null, 0.1, $errno));
                throw new ConnectionException('Synthetic handshake failure');
            }

            return Http::response(str_contains($request->url(), '/system/identity') ? ['name' => 'Synthetic router'] : ['version' => '7.17']);
        }]);
        $this->post($this->url('verify'), ['revision' => $this->data()['revision']])->assertSessionHasNoErrors();
        $connection = $this->device->mikrotikConnection()->sole();
        $this->assertSame($status, $connection->check_status);
        $this->assertSame($expectedAttempts, $attempts);
        $this->assertSame($allow && $errno === 35 ? 1 : 0, substr_count($connection->check_log, 'Nuovo tentativo: TLS 1.2 anonimo'));
        $this->assertSame($status === 'tls' ? 1 : 0, substr_count($connection->check_log, 'Nuovo tentativo: handshake TLS interrotto'));
        $this->assertStringNotContainsString('synthetic-secret', $connection->check_log);
    }

    #[TestWith(['127.0.0.1'])]
    #[TestWith(['169.254.169.254'])]
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
            $this->assertArrayNotHasKey(CURLOPT_SSL_CIPHER_LIST, $options['curl']);
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
        $connection = $this->device->mikrotikConnection()->sole();
        $this->assertSame($status, $connection->check_status);
        $this->assertStringContainsString('HTTP '.$code, $connection->check_log);
        $this->assertStringNotContainsString('synthetic-secret', $connection->check_log);
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

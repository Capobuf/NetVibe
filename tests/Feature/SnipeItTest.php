<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SnipeItCompanyLink;
use App\Models\SnipeItConnection;
use App\Models\SnipeItSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SnipeItTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        URL::forceScheme('https');
    }

    public function test_all_integration_endpoints_require_superadmin_even_for_company_members(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $other = Company::create(['name' => 'Beta']);
        $user = User::factory()->create(['is_superadmin' => false]);
        $company->users()->attach($user);
        $this->get($this->url('edit'))->assertRedirect(route('login'));
        $this->actingAs($user);
        foreach ([null, $company, $other] as $target) {
            $this->get($this->url('edit', $target))->assertForbidden();
            $this->put($this->url('update', $target), [])->assertForbidden();
            $this->delete($this->url('destroy', $target), [])->assertForbidden();
            $this->postJson($this->url('test', $target), [])->assertForbidden();
            if ($target) {
                $this->postJson($this->url('companies', $target), [])->assertForbidden();
                $this->post($this->url('associate', $target), [])->assertForbidden();
            }
        }
        $this->get(route('companies.settings.show', $company))->assertOk();
        $this->assertDatabaseCount('snipe_it_connections', 0);
        Http::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_entering_keys_over_http_supports_saving_and_verification(bool $dedicated): void
    {
        $this->admin();
        URL::forceScheme('http');
        $company = $dedicated ? Company::create(['name' => 'Alfa']) : null;
        $this->put($this->url('update', $company), $this->data(['use_global' => false], $company))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('secret-key', SnipeItSetting::forCompany($company)->connection->api_key);
        $this->fakeServer();
        $this->postJson($this->url('test', $company), $this->data(['use_global' => false], $company))
            ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('draft', false)->assertDontSee('secret-key');
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://192.168.10.20/api/v1/hardware?limit=1');
    }

    public function test_keys_are_encrypted_hidden_and_preserved_with_empty_input(): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data(['server_url' => 'https://192.168.10.20/inventory///']))->assertSessionHasNoErrors()->assertRedirect();
        $connection = SnipeItSetting::findOrFail('global')->connection;
        $cipher = DB::table('snipe_it_connections')->value('api_key');
        $this->assertNotSame('secret-key', $cipher);
        $this->assertSame('secret-key', Crypt::decryptString($cipher));
        $this->assertArrayNotHasKey('api_key', $connection->toArray());
        $this->get($this->url('edit'))->assertOk()->assertDontSee('secret-key')->assertInertia(fn (Assert $page) => $page
            ->component('integrations/snipe-it')->where('settings.connection.server_url', 'https://192.168.10.20/inventory')
            ->missing('settings.connection.api_key')->missing('settings.own_connection.api_key')->missing('settings.global_connection.api_key'));
        $this->put($this->url('update'), $this->data(['server_url' => $connection->server_url, 'api_key' => '']))->assertSessionHasNoErrors();
        $this->assertSame($cipher, DB::table('snipe_it_connections')->value('api_key'));
        Http::assertNothingSent();
    }

    #[TestWith(['http://192.168.10.20'])]
    #[TestWith(['https://localhost'])]
    #[TestWith(['https://127.0.0.1'])]
    #[TestWith(['https://169.254.169.254'])]
    #[TestWith(['https://100.100.100.200'])]
    #[TestWith(['https://metadata.google.internal'])]
    #[TestWith(['https://user:secret-key@192.168.10.20'])]
    #[TestWith(['https://192.168.10.20/#secret-key'])]
    #[TestWith(['https://192.168.10.20/?token=secret-key'])]
    #[TestWith(['https://192.168.10.20/api/v1/'])]
    #[TestWith(['https://2130706433'])]
    #[TestWith(['https://0x7f000001'])]
    public function test_unsafe_or_api_urls_never_receive_credentials(string $url): void
    {
        $this->admin();
        $response = $this->postJson($this->url('test'), $this->data(['server_url' => $url]));
        $response->assertUnprocessable()->assertJsonValidationErrors('server_url');
        $response->assertDontSee('secret-key');
        Http::assertNothingSent();
    }

    public function test_invalid_header_characters_are_rejected_without_exposing_the_key(): void
    {
        $this->admin();
        $this->postJson($this->url('test'), $this->data(['api_key' => "secret\0-key"]))
            ->assertUnprocessable()->assertJsonValidationErrors('api_key')->assertDontSee('secret-key');
        Http::assertNothingSent();
    }

    public function test_validation_never_flashes_the_key_or_an_invalid_url_with_credentials(): void
    {
        $this->admin();
        $this->put($this->url('update'), $this->data(['server_url' => 'https://user:secret-key@host']))
            ->assertSessionHasErrors('server_url')->assertSessionMissing('_old_input.api_key')->assertSessionMissing('_old_input.server_url');
        Http::assertNothingSent();
    }

    public function test_verification_uses_unsaved_values_without_changing_active_settings(): void
    {
        $this->admin();
        $connection = $this->globalConnection();
        Http::fake([
            'https://192.168.10.21/api/v1/companies*' => function (Request $request, array $options) {
                $this->assertFalse($options['allow_redirects']);
                $this->assertTrue($options['verify']);
                $this->assertSame('', $options['proxy']);
                $this->assertSame('v4', $options['force_ip_resolve']);
                $this->assertArrayNotHasKey(CURLOPT_IPRESOLVE, $options['curl']);
                $this->assertSame(['192.168.10.21:443:192.168.10.21'], $options['curl'][CURLOPT_RESOLVE]);

                return Http::response(['total' => 0, 'rows' => []]);
            },
            'https://192.168.10.21/api/v1/hardware*' => Http::response(['total' => 0, 'rows' => []]),
        ]);
        $response = $this->postJson($this->url('test'), $this->data(['server_url' => 'https://192.168.10.21/', 'api_key' => 'draft-key']));
        $response->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('draft', true)->assertDontSee('draft-key');
        $this->assertNull($connection->fresh()->checked_at);
        $this->assertSame('secret-key', $connection->fresh()->api_key);
        $this->assertDatabaseCount('snipe_it_connections', 1);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://192.168.10.21/api/v1/companies?')
            && $request->hasHeader('Authorization', 'Bearer draft-key')
            && $request->hasHeader('Accept', 'application/json') && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('User-Agent', 'NetVibe'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://192.168.10.21/api/v1/hardware?limit=1');
    }

    #[TestWith([401, ['message' => 'secret-key'], 'unauthorized'])]
    #[TestWith([403, ['message' => 'secret-key'], 'forbidden'])]
    #[TestWith([429, ['message' => 'secret-key'], 'rate_limit'])]
    #[TestWith([302, '', 'redirect'])]
    #[TestWith([500, ['message' => 'secret-key'], 'http_error'])]
    #[TestWith([200, '<html>secret-key</html>', 'html'])]
    #[TestWith([200, ['status' => 'error', 'messages' => 'secret-key'], 'api_error'])]
    #[TestWith([200, ['unexpected' => 'secret-key'], 'format'])]
    public function test_errors_are_distinguished_without_leaking_upstream_details(int $httpStatus, array|string $body, string $status): void
    {
        $this->admin();
        $connection = $this->globalConnection();
        Log::spy();
        Http::fake(['https://192.168.10.20/api/v1/companies*' => Http::response($body, $httpStatus, ['Location' => 'https://127.0.0.1'])]);
        $this->postJson($this->url('test'), $this->data(['api_key' => '']))
            ->assertOk()->assertJsonPath('status', $status)->assertJsonPath('draft', false)->assertDontSee('secret-key');
        $this->assertSame($status, $connection->fresh()->check_status);
        $this->assertNotNull($connection->fresh()->checked_at);
        Http::assertSentCount(1);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_network_failures_and_missing_hardware_permission_are_not_successful_checks(): void
    {
        $this->admin();
        $connection = $this->globalConnection();
        Http::fake(['https://192.168.10.20/api/v1/companies*' => Http::failedConnection('secret-key')]);
        $this->postJson($this->url('test'), $this->data(['api_key' => '']))->assertOk()->assertJsonPath('status', 'network')->assertDontSee('secret-key');
        Http::assertSentCount(1);
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            'https://192.168.10.20/api/v1/companies*' => Http::response(['total' => 0, 'rows' => []]),
            'https://192.168.10.20/api/v1/hardware*' => Http::response([], 403),
        ]);
        $this->postJson($this->url('test'), $this->data(['api_key' => '']))->assertOk()->assertJsonPath('status', 'forbidden');
        $this->assertSame('forbidden', $connection->fresh()->check_status);
        Http::assertSentCount(2);
    }

    public function test_global_inheritance_pagination_explicit_link_and_rename_use_ids(): void
    {
        $this->admin();
        $company = Company::create(['name' => 'Alfa']);
        $connection = $this->globalConnection();
        Http::fake(['https://192.168.10.20/api/v1/companies*' => function (Request $request) {
            $rows = (int) $request['offset'] === 0 ? [['id' => 7, 'name' => 'Beta']] : [['id' => 8, 'name' => 'Alfa']];

            return Http::response(['total' => 2, 'rows' => $rows]);
        }]);
        $this->get($this->url('edit', $company))->assertInertia(fn (Assert $page) => $page
            ->where('settings.use_global', true)->where('settings.connection.id', $connection->id)
            ->where('settings.own_connection', null)->where('settings.association', null)->missing('settings.global_connection.api_key'));
        $this->postJson($this->url('companies', $company), $this->data([], $company))->assertOk()
            ->assertJsonPath('suggested_id', 8)->assertJsonCount(2, 'companies');
        $this->assertDatabaseCount('snipe_it_company_links', 0);
        $this->post($this->url('associate', $company), [...$this->data([], $company), 'connection_id' => $connection->id, 'external_company_id' => 8])->assertSessionHasNoErrors();
        Http::assertSentCount(4);
        $this->fakeServer(rows: [['id' => 8, 'name' => 'Alfa rinominata']]);
        $this->postJson($this->url('companies', $company), $this->data([], $company))->assertOk()->assertJsonPath('companies.0.name', 'Alfa rinominata');
        $this->get($this->url('edit', $company))->assertInertia(fn (Assert $page) => $page
            ->where('settings.association.external_company_id', 8)->where('settings.association.confirmed', true));
        $this->assertNull(SnipeItSetting::forCompany($company)->connection_id);
        Http::assertSentCount(1);
    }

    public function test_ambiguous_names_are_not_suggested_and_invisible_ids_are_rejected(): void
    {
        $this->admin();
        $company = Company::create(['name' => 'Alfa']);
        $connection = $this->globalConnection();
        $this->fakeServer(rows: [['id' => 7, 'name' => 'Alfa'], ['id' => 8, 'name' => 'Alfa']]);
        $this->postJson($this->url('companies', $company), $this->data([], $company))->assertJsonPath('suggested_id', null);
        $this->post($this->url('associate', $company), [...$this->data([], $company), 'connection_id' => $connection->id, 'external_company_id' => 999])->assertSessionHasErrors('external_company_id');
        $this->assertDatabaseCount('snipe_it_company_links', 0);
        Http::assertSentCount(2);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_realistic_payloads_support_verification_suggestion_and_association(bool $dedicated): void
    {
        $this->admin();
        $company = Company::create(['name' => "Rossi & Figli 'Nord' <R&D>"]);
        $global = $this->globalConnection();
        $connection = $global;
        if ($dedicated) {
            $connection = SnipeItConnection::create(['server_url' => 'https://192.168.10.21', 'api_key' => 'dedicated-key']);
            SnipeItSetting::forCompany($company)->fill(['use_global' => false, 'connection_id' => $connection->id])->save();
        }
        Http::fake([
            $connection->server_url.'/api/v1/companies*' => Http::response($this->fixture('companies')),
            $connection->server_url.'/api/v1/hardware*' => Http::response($this->fixture('hardware')),
        ]);

        $this->postJson($this->url('test', $company), $this->data([
            'use_global' => ! $dedicated, 'server_url' => $connection->server_url, 'api_key' => '',
        ], $company))->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('draft', false)
            ->assertDontSee('TEST-SERIAL');
        $this->postJson($this->url('companies', $company), $this->data([], $company))->assertOk()->assertExactJson([
            'companies' => [['id' => 7, 'name' => $company->name], ['id' => 8, 'name' => 'Literal &amp;']],
            'connection_id' => $connection->id, 'suggested_id' => 7,
        ]);
        $this->post($this->url('associate', $company), [
            ...$this->data([], $company), 'connection_id' => $connection->id, 'external_company_id' => 7,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('snipe_it_company_links', [
            'company_id' => $company->id, 'connection_id' => $connection->id,
            'external_company_id' => 7, 'external_company_name' => $company->name,
        ]);
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET'
            || ! str_starts_with($request->url(), $connection->server_url.'/api/v1/')
            || ! $request->hasHeader('Authorization', 'Bearer '.$connection->api_key));
        if ($dedicated) {
            $this->assertNull($global->fresh()->checked_at);
        }
    }

    #[TestWith([255])]
    #[TestWith([256])]
    public function test_encoded_company_name_length_is_validated_after_decoding(int $length): void
    {
        $this->admin();
        $company = Company::create(['name' => 'Alfa']);
        $this->globalConnection();
        $this->fakeServer(rows: [['id' => 7, 'name' => str_repeat('&amp;', $length)]]);

        $response = $this->postJson($this->url('companies', $company), $this->data([], $company));
        if ($length === 255) {
            $response->assertOk()->assertJsonPath('companies.0.name', str_repeat('&', 255));
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('connection');
        }
        Http::assertSentCount(1);
    }

    #[TestWith(['empty'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['unauthorized'])]
    public function test_incomplete_company_pages_never_replace_an_existing_association(string $failure): void
    {
        $this->admin();
        $company = Company::create(['name' => 'Alfa']);
        $connection = $this->globalConnection();
        $link = $this->link($company, $connection);
        $row = $this->fixture('companies')['rows'][0];
        $sequence = Http::sequence()->push(['total' => 2, 'rows' => [$row]]);
        if ($failure === 'unauthorized') {
            $sequence->push(['message' => 'secret-key'], 401);
        } else {
            $sequence->push(['total' => 2, 'rows' => $failure === 'duplicate' ? [$row] : []]);
        }
        Http::fake([$connection->server_url.'/api/v1/companies*' => $sequence]);

        $this->postJson($this->url('associate', $company), [
            ...$this->data([], $company), 'connection_id' => $connection->id, 'external_company_id' => 7,
        ])->assertUnprocessable()->assertJsonValidationErrors('connection')->assertDontSee('secret-key');
        $this->assertNotNull($link->fresh()->confirmed_at);
        $this->assertSame('Alfa', $link->fresh()->external_company_name);
        $this->assertDatabaseCount('snipe_it_company_links', 1);
        Http::assertSentCount(2);
        $this->assertSame([0, 1], Http::recorded()->map(fn ($pair) => (int) $pair[0]['offset'])->all());
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_hardware_application_error_prevents_key_rotation_and_preserves_links(): void
    {
        $this->admin();
        $connection = $this->globalConnection();
        $company = Company::create(['name' => 'Alfa']);
        $link = $this->link($company, $connection);
        Http::fake([
            $connection->server_url.'/api/v1/companies*' => Http::response($this->fixture('companies')),
            $connection->server_url.'/api/v1/hardware*' => Http::response([
                'status' => 'error', 'messages' => 'restricted-key', 'payload' => null,
            ]),
        ]);

        $this->putJson($this->url('update'), $this->data(['api_key' => 'restricted-key']))
            ->assertUnprocessable()->assertJsonValidationErrors('api_key')->assertDontSee('restricted-key');
        $this->assertSame('secret-key', $connection->fresh()->api_key);
        $this->assertNull($connection->fresh()->checked_at);
        $this->assertNotNull($link->fresh()->confirmed_at);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_oversized_responses_are_rejected_before_hardware_is_read(): void
    {
        $this->admin();
        $this->globalConnection();
        Http::fake(['https://192.168.10.20/api/v1/companies*' => Http::response(str_repeat('x', 2_000_001))]);

        $this->postJson($this->url('test'), $this->data(['api_key' => '']))
            ->assertOk()->assertJsonPath('status', 'limit');
        Http::assertSentCount(1);
    }

    public function test_override_and_mode_changes_are_isolated_and_require_reconfirmation_even_when_returning(): void
    {
        $this->admin();
        $global = $this->globalConnection();
        $alfa = Company::create(['name' => 'Alfa']);
        $beta = Company::create(['name' => 'Beta']);
        $alfaLink = $this->link($alfa, $global);
        $betaLink = $this->link($beta, $global);
        $this->put($this->url('update', $beta), $this->data(['use_global' => false, 'server_url' => 'https://192.168.10.21'], $beta))->assertSessionHasNoErrors();
        $this->assertNull($betaLink->fresh()->confirmed_at);
        $this->assertNotNull($alfaLink->fresh()->confirmed_at);
        $dedicated = SnipeItSetting::forCompany($beta)->connection;
        $this->fakeServer('https://192.168.10.21');
        $this->postJson($this->url('test', $beta), $this->data(['use_global' => false, 'server_url' => $dedicated->server_url, 'api_key' => ''], $beta))->assertJsonPath('status', 'ok')->assertJsonPath('draft', false);
        $this->assertSame('ok', $dedicated->fresh()->check_status);
        Http::assertSentCount(2);
        $this->fakeServer();
        $this->postJson($this->url('test', $beta), $this->data(['use_global' => true, 'api_key' => ''], $beta))
            ->assertJsonPath('status', 'ok')->assertJsonPath('draft', true);
        $this->assertNull($global->fresh()->checked_at);
        $this->put($this->url('update', $beta), $this->data(['use_global' => true], $beta))->assertSessionHasNoErrors();
        $this->get($this->url('edit', $beta))->assertInertia(fn (Assert $page) => $page
            ->where('settings.connection.id', $global->id)->where('settings.association.confirmed', false));
        $this->assertDatabaseCount('snipe_it_connections', 2);
        Http::assertSentCount(2);
    }

    public function test_key_rotation_keeps_links_only_when_the_new_key_sees_linked_companies(): void
    {
        $this->admin();
        $connection = $this->globalConnection();
        $company = Company::create(['name' => 'Alfa']);
        $link = $this->link($company, $connection);
        $this->fakeServer();
        $this->put($this->url('update'), $this->data(['api_key' => 'rotated-key']))->assertSessionHasNoErrors();
        $this->assertSame('rotated-key', $connection->fresh()->api_key);
        $this->assertNotNull($link->fresh()->confirmed_at);
        Http::assertSentCount(2);
        $this->fakeServer(rows: []);
        $this->put($this->url('update'), $this->data(['api_key' => 'restricted-key']))->assertSessionHasErrors('api_key');
        $this->assertSame('rotated-key', $connection->fresh()->api_key);
        $this->assertNotNull($link->fresh()->confirmed_at);
        $this->assertDatabaseCount('snipe_it_connections', 1);
        Http::assertSentCount(2);
    }

    public function test_global_server_change_needs_confirmation_and_new_key_preserves_history_and_rejects_stale_forms(): void
    {
        $this->admin();
        $old = $this->globalConnection();
        $company = Company::create(['name' => 'Alfa']);
        $link = $this->link($company, $old);
        $stale = $this->data([], $company);
        $change = $this->data(['server_url' => 'https://192.168.10.21']);
        $this->put($this->url('update'), $change)->assertSessionHasErrors('confirm_change');
        $this->put($this->url('update'), [...$change, 'confirm_change' => true, 'api_key' => ''])->assertSessionHasErrors('api_key');
        $this->assertNotNull($link->fresh()->confirmed_at);
        $this->put($this->url('update'), [...$change, 'confirm_change' => true])->assertSessionHasNoErrors();
        $this->assertNull($link->fresh()->confirmed_at);
        $this->assertDatabaseHas('snipe_it_connections', ['id' => $old->id, 'server_url' => $old->server_url]);
        $this->assertDatabaseCount('snipe_it_connections', 2);
        $this->post($this->url('associate', $company), [...$stale, 'connection_id' => $old->id, 'external_company_id' => 7])->assertSessionHasErrors('connection');
        $this->post($this->url('associate', $company), [...$this->data([], $company), 'connection_id' => $old->id, 'external_company_id' => 7])->assertSessionHasErrors('connection');
        Http::assertNothingSent();
    }

    public function test_removal_preserves_documented_data_and_dedicated_connections(): void
    {
        $this->admin();
        $global = $this->globalConnection();
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Torino']);
        $link = $this->link($company, $global);
        $beta = Company::create(['name' => 'Beta']);
        $dedicated = SnipeItConnection::create(['server_url' => 'https://192.168.10.21', 'api_key' => 'dedicated-key']);
        $betaLink = $this->link($beta, $dedicated, false);
        $this->delete($this->url('destroy'), $this->data())->assertSessionHasErrors('confirm_change');
        $this->delete($this->url('destroy'), $this->data(['confirm_change' => true]))->assertSessionHasNoErrors();
        $this->assertNull(SnipeItSetting::findOrFail('global')->connection_id);
        $this->assertNull($link->fresh()->confirmed_at);
        $this->assertNotNull($betaLink->fresh()->confirmed_at);
        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        $this->delete($this->url('destroy', $beta), $this->data(['confirm_change' => true], $beta))->assertSessionHasNoErrors();
        $this->assertNull($betaLink->fresh()->confirmed_at);
        $this->assertFalse(SnipeItSetting::forCompany($beta)->use_global);
        $this->assertDatabaseCount('snipe_it_company_links', 2);
        Http::assertNothingSent();
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
    }

    private function url(string $action, ?Company $company = null): string
    {
        return $company ? route('companies.settings.integrations.snipe-it.'.$action, $company)
            : route('administration.integrations.snipe-it.'.$action);
    }

    private function data(array $values = [], ?Company $company = null): array
    {
        return [...[
            'use_global' => $company !== null, 'server_url' => 'https://192.168.10.20', 'api_key' => 'secret-key',
            'revision' => SnipeItSetting::forCompany($company)->revision,
            'global_revision' => SnipeItSetting::findOrFail('global')->revision,
        ], ...$values];
    }

    private function globalConnection(): SnipeItConnection
    {
        $connection = SnipeItConnection::create(['server_url' => 'https://192.168.10.20', 'api_key' => 'secret-key']);
        SnipeItSetting::findOrFail('global')->update(['connection_id' => $connection->id]);

        return $connection;
    }

    private function link(Company $company, SnipeItConnection $connection, bool $useGlobal = true): SnipeItCompanyLink
    {
        $setting = SnipeItSetting::forCompany($company);
        $setting->fill(['use_global' => $useGlobal, 'connection_id' => $useGlobal ? null : $connection->id])->save();

        return SnipeItCompanyLink::create([
            'company_id' => $company->id, 'connection_id' => $connection->id,
            'external_company_id' => 7, 'external_company_name' => $company->name, 'confirmed_at' => now(),
        ]);
    }

    private function fakeServer(string $url = 'https://192.168.10.20', array $rows = [['id' => 7, 'name' => 'Alfa']]): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $template = $this->fixture('companies')['rows'][0];
        Http::fake([
            $url.'/api/v1/companies*' => Http::response([
                'total' => count($rows), 'rows' => array_map(fn ($row) => [...$template, ...$row], $rows),
            ]),
            $url.'/api/v1/hardware*' => Http::response($this->fixture('hardware')),
        ]);
    }

    private function fixture(string $endpoint): array
    {
        // Synthetic values using the response structure observed on Snipe-IT 8.7.2; no customer data.
        return json_decode(file_get_contents(base_path('tests/Fixtures/snipe-it/'.$endpoint.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}

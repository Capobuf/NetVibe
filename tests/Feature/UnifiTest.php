<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\UnifiConnection;
use App\Models\UnifiSite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnifiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_site_identity_cannot_be_mapped_through_two_connections_and_reassignment_clears_old_preview(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $alfa = Company::create(['name' => 'Alfa'])->sites()->create(['name' => 'Sede Alfa']);
        $beta = Company::create(['name' => 'Beta'])->sites()->create(['name' => 'Sede Beta']);
        $first = $this->connection();
        $second = $this->connection();
        $source = $this->source($first);
        $duplicate = $this->source($second, ['external_site_id' => $source->external_site_id]);

        $this->put($this->url('associate', $first, $source), [
            'revision' => $first->revision, 'site_id' => $alfa->id, 'confirmed' => true,
        ])->assertSessionHasNoErrors();
        $this->put($this->url('associate', $second, $duplicate), [
            'revision' => $second->revision, 'site_id' => $beta->id, 'confirmed' => true,
        ])->assertSessionHasErrors('site_id');
        $this->assertNull($duplicate->fresh()->site_id);
        $this->assertSame($alfa->id, $source->fresh()->site_id);

        $this->put($this->url('associate', $first, $source), [
            'revision' => $first->fresh()->revision, 'site_id' => $beta->id, 'confirmed' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame($beta->id, $source->fresh()->site_id);
        $this->assertNull($source->fresh()->preview);
        $this->assertFalse($source->fresh()->available);
        Http::assertNothingSent();
    }

    public function test_company_members_only_see_their_sources_without_connection_configuration_or_secrets(): void
    {
        $alfa = Company::create(['name' => 'Alfa']);
        $beta = Company::create(['name' => 'Beta']);
        $local = $alfa->sites()->create(['name' => 'Sede Alfa']);
        $other = $beta->sites()->create(['name' => 'Sede Beta']);
        $user = User::factory()->create(['is_superadmin' => false]);
        $alfa->users()->attach($user);
        $connection = $this->connection();
        $source = $this->source($connection, ['site_id' => $local->id, 'confirmed_at' => now()]);
        $this->source($connection, ['site_id' => $other->id, 'name' => 'Private Beta site', 'confirmed_at' => now()]);

        $this->actingAs($user)->get(route('companies.settings.integrations.unifi.index', $alfa))
            ->assertOk()->assertDontSee('synthetic-secret')->assertDontSee('Private Beta site')->assertInertia(fn (Assert $page) => $page
            ->component('integrations/unifi')->has('connections', 1)->has('connections.0.sites', 1)
            ->where('connections.0.sites.0.id', $source->id)->missing('connections.0.api_key')
            ->missing('connections.0.server_url')->missing('connections.0.hosts')->missing('connections.0.revision'));
        $this->get(route('companies.settings.integrations.unifi.index', $beta))->assertForbidden();
        $this->get(route('administration.integrations.unifi.index'))->assertForbidden();
        $this->post($this->url('discover', $connection), ['revision' => $connection->revision])->assertForbidden();
        $this->put($this->url('associate', $connection, $source), [
            'revision' => $connection->revision, 'site_id' => $other->id, 'confirmed' => true,
        ])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_failed_discovery_does_not_publish_partial_data_or_replace_the_last_valid_preview(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $connection = $this->connection();
        $source = $this->source($connection);
        $preview = $source->preview;
        $previousRead = $source->discovered_at;
        $secondId = (string) Str::uuid();
        $base = $connection->server_url.'/proxy/network/integration/v1';
        $page = fn (array $rows) => ['offset' => 0, 'count' => count($rows), 'totalCount' => count($rows), 'data' => $rows];
        Http::fake([
            $base.'/info' => Http::response(['applicationVersion' => '11.0.97']),
            $base.'/sites?*' => Http::response($page([
                ['id' => $source->external_site_id, 'name' => 'Changed source'], ['id' => $secondId, 'name' => 'Second source'],
            ])),
            $base.'/sites/'.$source->external_site_id.'/devices?*' => Http::response($page([])),
            $base.'/sites/'.$source->external_site_id.'/networks?*' => Http::response($page([])),
            $base.'/sites/'.$secondId.'/devices?*' => Http::response(['error' => 'synthetic-secret'], 403),
        ]);

        $this->post($this->url('discover', $connection), ['revision' => $connection->revision])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('forbidden', $connection->fresh()->check_status);
        $this->assertSame($preview, $source->fresh()->preview);
        $this->assertSame('Synthetic source', $source->fresh()->name);
        $this->assertTrue($previousRead->equalTo($source->fresh()->discovered_at));
        $this->assertFalse($source->fresh()->available);
        $this->assertDatabaseCount('unifi_sites', 1);
        Http::assertSentCount(5);
    }

    private function connection(): UnifiConnection
    {
        return UnifiConnection::create([
            'name' => 'Synthetic connection', 'mode' => 'direct', 'server_url' => 'https://192.168.60.10:8443',
            'api_key' => 'synthetic-secret', 'allow_unverified_certificate' => false, 'enabled' => true,
            'revision' => (string) Str::uuid(), 'check_status' => 'ok', 'checked_at' => now(),
        ]);
    }

    private function source(UnifiConnection $connection, array $attributes = []): UnifiSite
    {
        return $connection->unifiSites()->create([
            'console_id' => 'direct', 'console_name' => 'Synthetic console', 'external_site_id' => (string) Str::uuid(),
            'name' => 'Synthetic source', 'version' => '11.0.97', 'available' => true, 'discovered_at' => now()->subHour(),
            'preview' => ['devices' => [['id' => (string) Str::uuid(), 'name' => 'Last valid device']], 'networks' => [], 'networks_status' => 'ok'],
            ...$attributes,
        ]);
    }

    private function url(string $action, UnifiConnection $connection, ?UnifiSite $site = null): string
    {
        return route('administration.integrations.unifi.'.$action, array_filter([$connection, $site]));
    }
}

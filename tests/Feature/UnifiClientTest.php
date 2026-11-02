<?php

namespace Tests\Feature;

use App\Exceptions\UnifiException;
use App\Models\UnifiConnection;
use App\Support\UnifiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UnifiClientTest extends TestCase
{
    public function test_site_pagination_uses_only_the_selected_cloud_console_and_discards_other_fields(): void
    {
        Http::preventStrayRequests();
        $connection = $this->connection();
        $connection->mode = 'cloud';
        $connection->hosts = [['id' => 'console:123', 'name' => 'Synthetic console']];
        $connection->selected_console_ids = ['console:123'];
        $url = 'https://api.ui.com/v1/connector/consoles/console%3A123/proxy/network/integration/v1/sites';
        Http::fake([
            $url.'?offset=0&limit=200' => Http::response([
                'offset' => 0, 'count' => 1, 'totalCount' => 2,
                'data' => [['id' => '00000000-0000-0000-0000-000000000001', 'name' => 'Site A', 'extra' => 'omit']],
            ]),
            $url.'?offset=1&limit=200' => Http::response([
                'offset' => 1, 'count' => 1, 'totalCount' => 2,
                'data' => [['id' => '00000000-0000-0000-0000-000000000002', 'name' => 'Site B']],
            ]),
        ]);

        $sites = $this->client()->sites($connection, 'console:123');

        $this->assertSame([
            ['id' => '00000000-0000-0000-0000-000000000001', 'name' => 'Site A'],
            ['id' => '00000000-0000-0000-0000-000000000002', 'name' => 'Site B'],
        ], $sites);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->hasHeader('X-API-Key', 'synthetic-api-key'));
    }

    public function test_refuses_cloud_console_ids_outside_the_discovered_selection(): void
    {
        Http::preventStrayRequests();
        $connection = $this->connection();
        $connection->mode = 'cloud';
        $connection->hosts = [['id' => 'console:123', 'name' => 'Synthetic console']];
        $connection->selected_console_ids = ['console:123'];

        try {
            $this->client()->info($connection, 'different-console');
            $this->fail('An undiscovered console must not be queried.');
        } catch (UnifiException $exception) {
            $this->assertSame('forbidden', $exception->status);
        }
        Http::assertNothingSent();
    }

    public function test_refuses_a_hostname_resolving_to_metadata_before_sending_the_key(): void
    {
        Http::preventStrayRequests();
        $client = new class extends UnifiClient
        {
            protected function resolve(string $host): array
            {
                return ['169.254.169.254'];
            }
        };

        try {
            $client->info($this->connection());
            $this->fail('A metadata destination must not be queried.');
        } catch (UnifiException $exception) {
            $this->assertSame('unsafe_url', $exception->status);
        }
        Http::assertNothingSent();
    }

    #[TestWith([401, 'unauthorized'])]
    #[TestWith([403, 'forbidden'])]
    #[TestWith([404, 'not_found'])]
    #[TestWith([429, 'rate_limit'])]
    public function test_http_errors_do_not_expose_upstream_secrets(int $status, string $expected): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://unifi.example.test:8443/proxy/network/integration/v1/info' => Http::response(['message' => 'synthetic-api-key'], $status)]);

        try {
            $this->client()->info($this->connection());
            $this->fail('An unsuccessful response must fail the read.');
        } catch (UnifiException $exception) {
            $this->assertSame($expected, $exception->status);
            $this->assertStringNotContainsString('synthetic-api-key', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        Http::assertSentCount(1);
        RateLimiter::clear('unifi-retry::direct');
    }

    public function test_device_details_keep_numbered_observations_without_inventing_connectors(): void
    {
        Http::preventStrayRequests();
        $site = '00000000-0000-0000-0000-000000000001';
        $id = '00000000-0000-0000-0000-000000000002';
        Http::fake(['https://unifi.example.test:8443/proxy/network/integration/v1/sites/'.$site.'/devices/'.$id => Http::response([
            'id' => $id, 'name' => 'Switch test', 'model' => 'USW', 'macAddress' => '00:11:22:33:44:55',
            'ipAddress' => '192.168.1.2', 'state' => 'ONLINE', 'features' => ['switching' => null],
            'firmwareVersion' => '7.1.0', 'interfaces' => ['ports' => [
                ['idx' => 2, 'connector' => 'SFP', 'state' => 'DOWN', 'maxSpeedMbps' => 1000],
                ['idx' => 1, 'connector' => 'RJ45', 'state' => 'UP', 'maxSpeedMbps' => 1000, 'speedMbps' => 1000],
            ]],
        ])]);

        $device = $this->client()->device($this->connection(), $site, $id);

        $this->assertSame(['switching'], $device['features']);
        $this->assertSame([1, 2], array_column($device['ports'], 'idx'));
        $this->assertSame(['RJ45', 'SFP'], array_column($device['ports'], 'connector'));
        $this->assertNull($device['ports'][1]['speed_mbps']);
        $this->assertNull($device['ports'][0]['poe']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    }

    public function test_a_changing_pagination_total_rejects_the_incomplete_snapshot(): void
    {
        Http::preventStrayRequests();
        $url = 'https://unifi.example.test:8443/proxy/network/integration/v1/sites';
        Http::fake([
            $url.'?offset=0&limit=200' => Http::response(['offset' => 0, 'count' => 1, 'totalCount' => 2,
                'data' => [['id' => '00000000-0000-0000-0000-000000000001', 'name' => 'A']]]),
            $url.'?offset=1&limit=200' => Http::response(['offset' => 1, 'count' => 0, 'totalCount' => 1, 'data' => []]),
        ]);

        try {
            $this->client()->sites($this->connection());
            $this->fail('A changing source cannot replace the last complete snapshot.');
        } catch (UnifiException $exception) {
            $this->assertSame('format', $exception->status);
        }
        Http::assertSentCount(2);
    }

    public function test_retry_after_prevents_further_requests_until_the_server_pause_expires(): void
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        $connection = $this->connection();
        $connection->id = 42901;
        RateLimiter::clear('unifi-retry:42901:direct');
        Http::fake(['https://unifi.example.test:8443/proxy/network/integration/v1/info' => Http::sequence()
            ->push([], 429, ['Retry-After' => '120'])
            ->push(['applicationVersion' => '10.1.84'])]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->client()->info($connection);
                $this->fail('The rate limit must be reported during the server pause.');
            } catch (UnifiException $exception) {
                $this->assertSame('rate_limit', $exception->status);
            }
        }
        Http::assertSentCount(1);
        $this->travel(121)->seconds();
        $this->assertSame('10.1.84', $this->client()->info($connection)['applicationVersion']);
        Http::assertSentCount(2);
        RateLimiter::clear('unifi-retry:42901:direct');
    }

    private function connection(): UnifiConnection
    {
        return new UnifiConnection([
            'mode' => 'direct', 'server_url' => 'https://unifi.example.test:8443',
            'api_key' => 'synthetic-api-key', 'allow_unverified_certificate' => false, 'enabled' => true,
        ]);
    }

    private function client(): UnifiClient
    {
        return new class extends UnifiClient
        {
            protected function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
    }
}

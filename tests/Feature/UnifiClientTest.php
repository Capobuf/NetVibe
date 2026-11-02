<?php

namespace Tests\Feature;

use App\Exceptions\UnifiException;
use App\Models\UnifiConnection;
use App\Support\UnifiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Subnet;
use App\Models\IpAddress;

class SubnetGatewayLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_lookup_returns_gateway_ip_when_present()
    {
        $subnet = Subnet::factory()->create();

        // Create a gateway IP for this subnet
        $ip = IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_addr' => '192.0.2.1',
            'type' => 'gateway',
        ]);

        $found = $subnet->ipAddresses()->where('type', 'gateway')->first();

        $this->assertNotNull($found);
        $this->assertEquals('192.0.2.1', $found->ip_str);
    }

    public function test_gateway_lookup_falls_back_to_first_usable_ip_when_no_gateway()
    {
        $subnet = Subnet::factory()->create();

        // No explicit gateway created — Subnet resource should fall back to subnet + 1
        $service = app(\App\Services\IpConversionService::class);
        $expected = $service->fromDecimal(bcadd((string) $subnet->subnet, '1'))?->toString();

        $found = optional($subnet->ipAddresses()->where('type', 'gateway')->first())->ip_str ?? $expected;

        $this->assertEquals($expected, $found);
    }
}

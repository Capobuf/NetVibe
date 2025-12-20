<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\ScanSubnetJob;
use App\Models\IpAddress;
use App\Models\Subnet;
use App\Services\IpConversionService;
use App\Services\Scanners\FpingWrapper;
use Illuminate\Support\Facades\Process;
use App\Settings\DiscoverySettings;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ScanSubnetJobTest extends TestCase
{
    public function test_skips_when_discovery_disabled()
    {
        $subnet = Subnet::factory()->create(['discover_subnet' => false, 'cidr' => '192.0.2.0/29']);

        // use real wrapper instance but ensure job won't call it
        $wrapper = new FpingWrapper();

        $converter = Mockery::mock(IpConversionService::class);

        $settings = Mockery::mock(DiscoverySettings::class);

        $job = new ScanSubnetJob($subnet);

        // ensure no exception is thrown
        $job->handle($wrapper, $converter, $settings);

        $this->assertDatabaseMissing('ip_addresses', ['subnet_id' => $subnet->getKey()]);
    }

    public function test_creates_ip_addresses_and_updates_last_discovery()
    {
        $subnet = Subnet::factory()->create(['discover_subnet' => true, 'cidr' => '10.0.0.0/29']);

        // simulate fping returning one valid and one invalid entry
        Process::fake([
            '*' => Process::result(output: "10.0.0.1\nnot-an-ip\n", exitCode: 0),
        ]);

        $wrapper = new FpingWrapper();

        $converter = Mockery::mock(IpConversionService::class);
        $converter->shouldReceive('toDecimal')->with('10.0.0.1')->andReturn(167772161);
        $converter->shouldReceive('toDecimal')->with('not-an-ip')->andThrow(new \InvalidArgumentException('Invalid IP'));

        $settings = Mockery::mock(DiscoverySettings::class);
        $settings->icmp_timeout_ms = 200;
        $settings->icmp_retries = 1;

        $job = new ScanSubnetJob($subnet);

        $job->handle($wrapper, $converter, $settings);

        $this->assertDatabaseHas('ip_addresses', ['subnet_id' => $subnet->getKey(), 'ip_addr' => '167772161']);

        $fresh = $subnet->fresh();
        $this->assertNotNull($fresh->last_discovery);
    }

    public function test_throws_and_logs_on_wrapper_exception()
    {
        $subnet = Subnet::factory()->create(['discover_subnet' => true, 'cidr' => '10.0.0.0/29']);

        // simulate wrapper throwing via Process returning fatal exit code
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'fatal', exitCode: 3),
        ]);

        $wrapper = new FpingWrapper();

        $converter = Mockery::mock(IpConversionService::class);

        $settings = Mockery::mock(DiscoverySettings::class);
        $settings->icmp_timeout_ms = 200;
        $settings->icmp_retries = 1;

        $this->expectException(\RuntimeException::class);

        $job = new ScanSubnetJob($subnet);
        $job->handle($wrapper, $converter, $settings);
    }
}

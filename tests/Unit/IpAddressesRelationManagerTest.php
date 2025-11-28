<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Customer;
use App\Models\Vrf;
use App\Models\Subnet;
use App\Models\IpAddress;
use App\Services\IpConversionService;
use App\Filament\Clusters\Ipam\Resources\Subnets\RelationManagers\IpAddressesRelationManager;

class IpAddressesRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent the Prefill job from running during these unit tests (isolation)
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\IpPrefillJob::class]);
    }

    public function test_ipv4_is_valid_when_not_present_in_subnet(): void
    {
        $customer = Customer::create(['name' => 'cust']);
        $vrf = Vrf::create(['customer_id' => $customer->id, 'name' => 'vrf1']);

        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
            'cidr' => '192.168.100.0/24',
        ]);

        // ensure validation accepts a valid IPv4
        $service = new IpConversionService();
        // ensure any pre-existing row for this subnet/ip is removed for test isolation
        $subnet->ipAddresses()->where('ip_addr', $service->toDecimal('192.168.100.10'))->delete();
        $this->assertTrue(IpAddressesRelationManager::isIpValidForSubnet($subnet, '192.168.100.10', null));
    }

    public function test_ipv6_is_rejected(): void
    {
        $customer = Customer::create(['name' => 'cust']);
        $vrf = Vrf::create(['customer_id' => $customer->id, 'name' => 'vrf1']);

        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
            'cidr' => '192.168.100.0/24',
        ]);

        $this->assertFalse(IpAddressesRelationManager::isIpValidForSubnet($subnet, '2001::1', null));
    }

    public function test_duplicate_ip_is_rejected(): void
    {
        $customer = Customer::create(['name' => 'cust']);
        $vrf = Vrf::create(['customer_id' => $customer->id, 'name' => 'vrf1']);

        // Use a distinct subnet so any prefilled IPs from other tests do not collide
        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
            'cidr' => '10.5.100.0/24',
        ]);

        $service = new IpConversionService();

        // Create (or find) an existing IP inside the subnet — use firstOrCreate to avoid
        // uniqueness errors if another test has already inserted the value.
        $subnet->ipAddresses()->firstOrCreate([
            'ip_addr' => $service->toDecimal('10.5.100.5')
        ], [
            'ip_str' => '10.5.100.5',
        ]);

        $this->assertFalse(IpAddressesRelationManager::isIpValidForSubnet($subnet, '192.168.200.5', null));
    }
}

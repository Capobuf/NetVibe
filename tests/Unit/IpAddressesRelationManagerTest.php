<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Customer;
use App\Models\Vrf;
use App\Models\Subnet;
use App\Models\IpAddress;
use App\Services\IpConversionService;
use App\Filament\Resources\Subnets\RelationManagers\IpAddressesRelationManager;

class IpAddressesRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ipv4_is_valid_when_not_present_in_subnet(): void
    {
        $customer = Customer::create(['name' => 'cust']);
        $vrf = Vrf::create(['customer_id' => $customer->id, 'name' => 'vrf1']);

        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
            'cidr' => '192.168.100.0/24',
        ]);

        $this->assertTrue(IpAddressesRelationManager::isIpValidForSubnet($subnet, null, '192.168.100.10'));
    }

    public function test_ipv6_is_rejected(): void
    {
        $customer = Customer::create(['name' => 'cust']);
        $vrf = Vrf::create(['customer_id' => $customer->id, 'name' => 'vrf1']);

        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
            'cidr' => '192.168.100.0/24',
        ]);

        $this->assertFalse(IpAddressesRelationManager::isIpValidForSubnet($subnet, null, '2001::1'));
    }

    public function test_duplicate_ip_is_rejected(): void
    {
        $customer = Customer::create(['name' => 'cust']);
        $vrf = Vrf::create(['customer_id' => $customer->id, 'name' => 'vrf1']);

        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
            'cidr' => '192.168.200.0/24',
        ]);

        $service = new IpConversionService();

        // Create existing IP
        $subnet->ipAddresses()->create([
            'ip_addr' => $service->toDecimal('192.168.200.5'),
            'ip_str' => '192.168.200.5',
        ]);

        $this->assertFalse(IpAddressesRelationManager::isIpValidForSubnet($subnet, null, '192.168.200.5'));
    }
}

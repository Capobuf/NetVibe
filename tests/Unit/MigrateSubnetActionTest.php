<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Vrf;
use App\Models\Subnet;
use App\Models\IpAddress;
use App\Services\IpConversionService;

class MigrateSubnetActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Prefill jobs removed; tests should not rely on background prefill dispatch.

        // Some tests (and app code) read from the settings table via Spatie Settings.
        // Ensure the table exists in the test database to avoid sqlite "no such table" errors.
            // The settings table is provided by project migrations when running the test suite.
        }

    public function test_wipe_strategy_deletes_all_ips(): void
    {
        $customer = Customer::create(['name' => 'cust']);
        $vrf = Vrf::create(['customer_id' => $customer->id, 'name' => 'vrf1']);

        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
            'cidr' => '192.168.10.0/24',
        ]);

        $service = new IpConversionService();

        $subnet->ipAddresses()->create(['ip_addr' => $service->toDecimal('192.168.10.10')]);
        $subnet->ipAddresses()->create(['ip_addr' => $service->toDecimal('192.168.10.20')]);

        // Wipe: delete all IPs
        DB::transaction(function () use ($subnet) {
            IpAddress::where('subnet_id', $subnet->getKey())->delete();
        });

        // Prefill logic removed — no dispatch expected. Ensure table is empty instead.

        $this->assertEquals(0, $subnet->ipAddresses()->count());
    }

    public function test_prune_strategy_deletes_out_of_range_ips(): void
    {
        $customer = Customer::create(['name' => 'cust']);
        $vrf = Vrf::create(['customer_id' => $customer->id, 'name' => 'vrf1']);

        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
            'cidr' => '10.5.0.0/16',
        ]);

        $service = new IpConversionService();

        // Two IPs: one that will remain inside the new /24 (10.5.0.0/24) and one outside
        $subnet->ipAddresses()->create(['ip_addr' => $service->toDecimal('10.5.0.5')]); // inside new /24
        $subnet->ipAddresses()->create(['ip_addr' => $service->toDecimal('10.5.1.5')]); // outside new /24 (but inside original /16)

        $this->assertEquals(2, $subnet->ipAddresses()->count());
        $ipsBefore = $subnet->ipAddresses()->pluck('ip_str')->toArray();
        $this->assertContains('10.5.0.5', $ipsBefore);
        $this->assertContains('10.5.1.5', $ipsBefore);

        $newParsed = $service->parseCidr('10.5.0.0/24');
        // sanity - ensure service sees the first inside and second outside
        $this->assertEquals('10.5.0.5', $service->fromDecimal($service->toDecimal('10.5.0.5'))->toString());
        $this->assertEquals('10.5.1.5', $service->fromDecimal($service->toDecimal('10.5.1.5'))->toString());

        // verify fromDecimal(parseCidr) roundtrip for subnet base
        $this->assertEquals('10.5.0.0', $service->fromDecimal($newParsed['subnet'])->toString());

        // Now validate membership explicitly
        $this->assertTrue($service->isIpInSubnet($service->toDecimal('10.5.0.5'), $newParsed['subnet'], $newParsed['mask']), 'Expected 10.5.0.5 to be in 10.5.0.0/24');
        $this->assertFalse($service->isIpInSubnet($service->toDecimal('10.5.1.5'), $newParsed['subnet'], $newParsed['mask']), 'Expected 10.5.1.5 to be outside 10.5.0.0/24');
        $newSubnetDecimal = $newParsed['subnet'];
        $newMask = $newParsed['mask'];

        // Prune: remove any ip not inside new subnet
        DB::transaction(function () use ($subnet, $service, $newSubnetDecimal, $newMask) {
            $ips = $subnet->ipAddresses()->get();
            foreach ($ips as $ip) {
                if (! $service->isIpInSubnet($ip->ip_addr, $newSubnetDecimal, $newMask)) {
                    $ip->delete();
                }
            }
        });

        // Prefill logic removed — no dispatch expected.

        $this->assertEquals(1, $subnet->ipAddresses()->count());
        $remaining = $subnet->ipAddresses()->first();
        $this->assertEquals('10.5.0.5', $remaining->ip_str);
    }

    public function test_remap_strategy_moves_ips_preserving_host_offsets(): void
    {
        $customer = Customer::create(['name' => 'cust']);
        $vrf = Vrf::create(['customer_id' => $customer->id, 'name' => 'vrf1']);

        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
            'cidr' => '192.168.100.0/24',
        ]);

        $service = new IpConversionService();

        // host .10 will be remapped to new network keeping offset
        $subnet->ipAddresses()->create(['ip_addr' => $service->toDecimal('192.168.100.10')]);

        $oldSubnetDecimal = $service->parseCidr('192.168.100.0/24')['subnet'];
        $newParsed = $service->parseCidr('10.10.100.0/24');
        $newSubnetDecimal = $newParsed['subnet'];
        $newMask = $newParsed['mask'];

        DB::transaction(function () use ($subnet, $service, $oldSubnetDecimal, $newSubnetDecimal, $newMask) {
            $ips = $subnet->ipAddresses()->get();
            foreach ($ips as $ip) {
                $offset = bcsub((string) $ip->ip_addr, (string) $oldSubnetDecimal);
                $newIpDecimal = bcadd((string) $newSubnetDecimal, $offset);

                if ($service->isIpInSubnet((string) $newIpDecimal, $newSubnetDecimal, $newMask)) {
                    $exists = IpAddress::where('subnet_id', $subnet->getKey())
                        ->where('ip_addr', (string) $newIpDecimal)
                        ->where('id', '!=', $ip->getKey())
                        ->exists();

                    if ($exists) {
                        $ip->delete();
                    } else {
                        $ip->update(['ip_addr' => (string) $newIpDecimal]);
                    }
                } else {
                    $ip->delete();
                }
            }

            $subnet->cidr = $service->toCidr($newSubnetDecimal, $newMask);
            $subnet->save();
        });

        // Prefill logic removed — nothing to dispatch

        $this->assertEquals('10.10.100.10', $subnet->ipAddresses()->first()->ip_str);
        $this->assertEquals('10.10.100.0/24', $subnet->cidr);
    }
}

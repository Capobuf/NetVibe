<?php

namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\Subnet;
use App\Services\IpConversionService;
use App\Services\IpPrefillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Bus;

class IpPrefillServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefill_creates_expected_number_of_ips()
    {
        Bus::fake();

        $ipService = new IpConversionService();

        $cidrBase = '192.168.200.0';
        $mask = 29; // 8 addresses

        // create subnet record (store subnet as decimal string)
        $customer = \App\Models\Customer::factory()->create();
        $vrf = \App\Models\Vrf::factory()->create(['customer_id' => $customer->id]);

        $subnetDecimal = $ipService->toDecimal($cidrBase);
        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
            'subnet' => $subnetDecimal,
            'mask' => $mask,
            'description' => 'test',
        ]);

        $service = new IpPrefillService();
        $result = $service->prefill($subnet, 200);

        $this->assertArrayHasKey('inserted', $result);

        // Expect 6 host addresses for mask=29 (8 total minus network & broadcast)
        $this->assertEquals(6, $result['inserted']);

        // DB should contain these host addresses
        $count = IpAddress::where('subnet_id', $subnet->id)->count();
        $this->assertEquals(6, $count);

        // Prefilled addresses must have last_seen = null (empty)
        $nullLastSeenCount = IpAddress::where('subnet_id', $subnet->id)->whereNull('last_seen')->count();
        $this->assertEquals(6, $nullLastSeenCount);
    }

    public function test_prefill_skips_existing_entries_and_respects_mask_limit()
    {
        Bus::fake();

        $ipService = new IpConversionService();

        $cidrBase = '192.168.201.0';
        $mask = 29; // 8 addresses

        $subnetDecimal = $ipService->toDecimal($cidrBase);
        $customer2 = \App\Models\Customer::factory()->create();
        $vrf2 = \App\Models\Vrf::factory()->create(['customer_id' => $customer2->id]);

        $subnet = Subnet::create([
            'vrf_id' => $vrf2->id,
            'subnet' => $subnetDecimal,
            'mask' => $mask,
            'description' => 'test',
        ]);

        // Create one pre-existing IP (state=0) that should not be duplicated
        $someIp = $ipService->toDecimal('192.168.201.3');
        IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_addr' => $someIp,
            'state' => 0,
        ]);

        $service = new IpPrefillService();
        $result = $service->prefill($subnet);

        // 6 host addresses - 1 existing = 5 inserted
        $this->assertEquals(5, $result['inserted']);

        $this->assertEquals(6, IpAddress::where('subnet_id', $subnet->id)->count());

        // Newly inserted records should have last_seen = null; the pre-existing item may have default
        $nullLastSeenCount = IpAddress::where('subnet_id', $subnet->id)->whereNull('last_seen')->count();
        // Since the table default for last_seen is NULL, the pre-existing record will also
        // have NULL unless explicitly set — so we should expect 6 NULLs (1 pre-existing + 5 new).
        $this->assertEquals(6, $nullLastSeenCount);

        // Test mask too small (too big subnet) is rejected
        $customer3 = \App\Models\Customer::factory()->create();
        $vrf3 = \App\Models\Vrf::factory()->create(['customer_id' => $customer3->id]);

        $subnet2 = Subnet::create(['vrf_id' => $vrf3->id, 'subnet' => $ipService->toDecimal('10.0.0.0'), 'mask' => 21]);

        $this->expectException(\InvalidArgumentException::class);
        $service->prefill($subnet2);
    }
}

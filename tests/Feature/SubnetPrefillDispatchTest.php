<?php

namespace Tests\Feature;

use App\Models\Subnet;
use App\Services\IpConversionService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubnetPrefillDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefill_job_dispatched_for_allowed_masks()
    {
        Bus::fake();

        $service = new IpConversionService();

        $customer = \App\Models\Customer::factory()->create();
        $vrf = \App\Models\Vrf::factory()->create(['customer_id' => $customer->id]);

        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
            'subnet' => $service->toDecimal('10.10.0.0'),
            'mask' => 24,
        ]);

        // Observer must not dispatch any prefill job (prefill removed)
        Bus::assertNothingDispatched();
    }

    public function test_prefill_job_not_dispatched_for_too_large_subnets()
    {
        Bus::fake();

        $service = new IpConversionService();
        $customer2 = \App\Models\Customer::factory()->create();
        $vrf2 = \App\Models\Vrf::factory()->create(['customer_id' => $customer2->id]);

        $subnet = Subnet::create([
            'vrf_id' => $vrf2->id,
            'subnet' => $service->toDecimal('10.0.0.0'),
            'mask' => 21, // too large (mask < 22)
        ]);

        Bus::assertNothingDispatched();
    }
}

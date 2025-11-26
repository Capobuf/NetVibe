<?php

namespace Tests\Feature;

use App\Jobs\IpPrefillJob;
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
        $subnet = Subnet::create([
            'subnet' => $service->toDecimal('10.10.0.0'),
            'mask' => 24,
        ]);

        // Observer should dispatch the job when created
        Bus::assertDispatched(IpPrefillJob::class);
    }

    public function test_prefill_job_not_dispatched_for_too_large_subnets()
    {
        Bus::fake();

        $service = new IpConversionService();
        $subnet = Subnet::create([
            'subnet' => $service->toDecimal('10.0.0.0'),
            'mask' => 21, // too large (mask < 22)
        ]);

        Bus::assertNotDispatched(IpPrefillJob::class);
    }
}

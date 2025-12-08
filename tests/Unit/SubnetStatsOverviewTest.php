<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Clusters\Ipam\Resources\Subnets\Widgets\SubnetStatsOverview;
use App\Models\Subnet;
use App\Models\IpAddress;
use App\Services\IpConversionService;

class SubnetStatsOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_return_expected_values()
    {
        $subnet = Subnet::create([
            'customer_id' => \App\Models\Customer::factory()->create()->id,
            'cidr' => '192.168.200.0/29',
        ]);

        // create a few ip addresses in this subnet
        IpAddress::factory()->count(2)->create(['subnet_id' => $subnet->id]);

        $ipService = app(IpConversionService::class);
        $mask = (int) $subnet->mask;
        $usable = (int) $ipService->calculateUsableHosts($mask);

        $widget = new SubnetStatsOverview();
        $widget->record = $subnet;

        // get protected stats via reflection
        $ref = new \ReflectionClass($widget);
        $method = $ref->getMethod('getStats');
        $method->setAccessible(true);

        $stats = $method->invoke($widget);

        // Expect 4 Stat objects (Usable, Used, Free, Occupancy)
        $this->assertIsArray($stats);
        $this->assertCount(4, $stats);

        // Stat objects expose getValue() — check numeric values
        $this->assertEquals($usable, (int) $stats[0]->getValue());
        $this->assertEquals(2, (int) $stats[1]->getValue());

        $free = max(0, $usable - 2);
        $this->assertEquals($free, (int) $stats[2]->getValue());

        // Occupancy should be percentage (0-100) — ensure it's an int and within range
        $occupancy = (int) filter_var((string) $stats[3]->getValue(), FILTER_SANITIZE_NUMBER_INT);
        $this->assertGreaterThanOrEqual(0, $occupancy);
        $this->assertLessThanOrEqual(100, $occupancy);
    }
}

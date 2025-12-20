<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Clusters\Ipam\Resources\Subnets\Widgets\IpAddressVisualGrid;
use App\Models\Subnet;

class IpAddressVisualGridTest extends TestCase
{
    use RefreshDatabase;

    public function test_grid_renders_for_small_subnet()
    {
        $subnet = Subnet::create([
            'customer_id' => \App\Models\Customer::factory()->create()->id,
            'cidr' => '192.168.200.0/29',
        ]);

        $widget = new IpAddressVisualGrid();
        $widget->record = $subnet;
        $widget->mount();

        $this->assertTrue($widget->renderGrid, 'Expected grid to render for /29 subnet');
        $this->assertCount(6, $widget->cells, 'Expected 6 usable host cells for /29');
    }

    public function test_grid_disables_for_large_subnet()
    {
        $subnet = Subnet::create([
            'customer_id' => \App\Models\Customer::factory()->create()->id,
            'cidr' => '10.0.0.0/21',
        ]);

        $widget = new IpAddressVisualGrid();
        $widget->record = $subnet;
        $widget->mount();

        $this->assertFalse($widget->renderGrid, 'Expected grid to be disabled for /21 subnet (performance guard)');
    }
}

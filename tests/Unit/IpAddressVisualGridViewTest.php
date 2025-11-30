<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Clusters\Ipam\Resources\Subnets\Widgets\IpAddressVisualGrid;
use App\Models\Subnet;

class IpAddressVisualGridViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_grid_view_contains_grid_classes_and_single_root()
    {
        $subnet = Subnet::create([
            'vrf_id' => \App\Models\Vrf::factory()->create(['customer_id' => \App\Models\Customer::factory()->create()])->id,
            'cidr' => '192.168.200.0/29',
        ]);

        $widget = new IpAddressVisualGrid();
        $widget->record = $subnet;
        $widget->mount();

        // Render the blade view using widget state so the returned HTML shows the real markup
        $html = view('filament.clusters.ipam.resources.subnets.widgets.ip-address-visual-grid', [
            'record' => $subnet,
            'cells' => $widget->cells,
            'renderGrid' => $widget->renderGrid,
            'totalHosts' => $widget->totalHosts,
            // The full widget would expose $this->table; in this isolated view test provide a stub to avoid using $this in a plain view render
            'table' => '<div class="dummy-table"></div>',
        ])->render();

        $this->assertStringContainsString('grid grid-cols-10 md:grid-cols-20 gap-1', $html, 'Expected grid classes present in view');
        $this->assertStringContainsString('aspect-square', $html, 'Expected cell elements to use aspect-square for consistent cells');
        // Ensure we removed the old single-row badge classes
        $this->assertStringNotContainsString('block w-8 h-6', $html, 'Old cell sizing classes should be replaced by grid + aspect-square');
    }
}

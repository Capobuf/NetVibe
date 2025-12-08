<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Clusters\Ipam\Resources\Subnets\Widgets\SubnetIpCompositionChart;
use App\Models\Subnet;
use App\Models\IpAddress;
use App\Enums\IpAddressType;
use App\Services\IpConversionService;

class SubnetIpCompositionChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_chart_data_matches_expected_counts()
    {
        $subnet = Subnet::create([
            'customer_id' => \App\Models\Customer::factory()->create()->id,
            'cidr' => '192.168.200.0/29',
        ]);

        // create a mix of ip types
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'type' => IpAddressType::Gateway]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'type' => IpAddressType::Reserved]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'type' => IpAddressType::DHCP]);
        IpAddress::factory()->count(2)->create(['subnet_id' => $subnet->id, 'type' => IpAddressType::Host]);

        $ipService = app(IpConversionService::class);
        $mask = (int) $subnet->mask;
        $usable = (int) $ipService->calculateUsableHosts($mask);

        $widget = new SubnetIpCompositionChart();
        $widget->record = $subnet;
        $widget->mount();

        $this->assertIsArray($widget->chartData);

        // expected order as defined in the widget mapping
        $expectedLabels = ['Gateway', 'Reserved', 'DHCP', 'Hosts', 'Broadcast', 'Free'];
        $this->assertEquals($expectedLabels, $widget->chartData['labels']);

        // dataset values: gateway=1, reserved=1, dhcp=1, hosts=2, broadcast=0, free=usable - 5
        $expectedData = [1, 1, 1, 2, 0, max(0, $usable - 5)];

        $this->assertEquals($expectedData, $widget->chartData['datasets'][0]['data']);
    }
}

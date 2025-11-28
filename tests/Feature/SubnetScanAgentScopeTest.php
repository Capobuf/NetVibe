<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ScanAgent;
use App\Models\Subnet;
use App\Services\IpConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubnetScanAgentScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_subnet_can_use_global_scan_agent_even_with_customer()
    {
        $ipService = new IpConversionService();

        $customer = Customer::create(['title' => 'Client']);

        // Global agent (customer_id null)
        $globalAgent = ScanAgent::create(['name' => 'Global', 'type' => 'direct']);

        $subnet = Subnet::create([
            'subnet' => $ipService->toDecimal('192.168.10.0'),
            'mask' => 24,
            'customer_id' => $customer->id,
            'scan_agent_id' => $globalAgent->id,
        ]);

        $this->assertEquals($globalAgent->id, $subnet->scanAgent->id);
        $this->assertEquals($customer->id, $subnet->customer->id);
    }

    public function test_subnet_can_use_customer_scoped_scan_agent()
    {
        $ipService = new IpConversionService();

        $customer = Customer::create(['title' => 'ClientB']);

        $agent = ScanAgent::create(['name' => 'AgentB', 'type' => 'direct', 'customer_id' => $customer->id]);

        $subnet = Subnet::create([
            'subnet' => $ipService->toDecimal('10.2.0.0'),
            'mask' => 24,
            'customer_id' => $customer->id,
            'scan_agent_id' => $agent->id,
        ]);

        $this->assertEquals($agent->id, $subnet->scanAgent->id);
        $this->assertEquals($customer->id, $subnet->customer->id);
    }
}

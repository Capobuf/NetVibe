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

    public function test_subnet_cannot_assign_agent_from_another_customer()
    {
        $ipService = new IpConversionService();

        $customerA = Customer::create(['name' => 'Client A']);
        $customerB = Customer::create(['name' => 'Client B']);

        $agentA = ScanAgent::create(['name' => 'Agent A', 'active' => true, 'customer_id' => $customerA->id]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        Subnet::create([
            'customer_id' => $customerB->id,
            'subnet' => $ipService->toDecimal('192.168.10.0'),
            'mask' => 24,
            'scan_agent_id' => $agentA->id,
        ]);
    }

    public function test_subnet_can_use_customer_scoped_scan_agent()
    {
        $ipService = new IpConversionService();

        $customer = Customer::create(['name' => 'ClientB']);

        $agent = ScanAgent::create(['name' => 'AgentB', 'active' => true, 'customer_id' => $customer->id]);

        $subnet = Subnet::create([
            'customer_id' => $customer->id,
            'subnet' => $ipService->toDecimal('10.2.0.0'),
            'mask' => 24,
            'scan_agent_id' => $agent->id,
        ]);

        $this->assertEquals($agent->id, $subnet->scanAgent->id);
        $this->assertEquals($customer->id, $subnet->customer->id);
    }
}

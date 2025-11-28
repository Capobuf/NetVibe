<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ScanAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanAgentCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_agent_can_belong_to_customer()
    {
        $customer = Customer::create(['name' => 'Acme Corp']);

        $agent = ScanAgent::create([
            'name' => 'Agent A',
            'type' => 'direct',
            'customer_id' => $customer->id,
        ]);

        $this->assertEquals($customer->id, $agent->customer->id);
        $this->assertDatabaseHas('scan_agents', ['id' => $agent->id, 'customer_id' => $customer->id]);
    }
}

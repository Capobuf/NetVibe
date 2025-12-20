<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\AgentJob;
use App\Models\User;
use App\Models\Customer;
use App\Models\ScanAgent;
use App\Actions\Agent\ProcessAgentJobResultAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Services\IpConversionService;
use App\Notifications\AgentJobCompletedNotification;

class ProcessAgentJobResultActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_connectivity_updates_device_and_notifications()
    {
        $user = User::factory()->create();

        $model = DeviceModel::create(['brand' => 'Acme', 'model' => 'X1', 'type' => 'switch']);
        $device = Device::create(['hostname' => 'dev-1', 'device_model_id' => $model->id]);

        $job = AgentJob::create([
            'device_id' => $device->id,
            'scan_agent_id' => null,
                'type' => 'check_connectivity',
                'status' => 'completed',
            'payload' => [],
            'result' => ['ok' => true, 'hostname' => 'dev-1'],
            'initiated_by_user_id' => $user->id,
        ]);

        app(ProcessAgentJobResultAction::class)->execute($job);

        $device->refresh();

        $this->assertEquals('valid', $device->auth_status);
        $this->assertNotNull($device->last_seen_at);

        // Notification exists for user and is Filament-formatted
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'type' => AgentJobCompletedNotification::class,
        ]);

        $notification = $user->notifications()->first();
        $this->assertEquals('filament', $notification->data['format'] ?? null);
        $this->assertStringContainsString((string) $job->id, $notification->data['body'] ?? '');
        $this->assertEquals('open_device', $notification->data['actions'][0]['name'] ?? null);
    }

    public function test_discovery_fast_creates_discovery_events_for_unknown_arp()
    {
        $customer = Customer::factory()->create();
        $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);
        // Ensure we have a device model so device creation satisfies DB requirements
        $model = \App\Models\DeviceModel::create(['brand' => 'Acme', 'model' => 'T1', 'type' => 'switch']);
        $device = \App\Models\Device::create([
            'hostname' => 'arp-host',
            'device_model_id' => $model->id,
            'customer_id' => $customer->id,
            'scan_agent_id' => $agent->id,
        ]);

        $job = AgentJob::create([
            'device_id' => $device->id,
            'scan_agent_id' => $agent->id,
            'type' => 'discovery_fast',
                'status' => 'completed',
            'payload' => [],
            'result' => ['arp_table' => [['address' => '192.168.5.10', 'mac-address' => 'aa:bb:cc:dd:ee:ff']]],
        ]);

        app(ProcessAgentJobResultAction::class)->execute($job);

        // The ARP entry was unknown and should create a pending DiscoveryEvent
        $converter = app(\App\Services\IpConversionService::class);
        $dec = $converter->toDecimal('192.168.5.10');

        $this->assertDatabaseHas('discovery_events', [
            'customer_id' => $customer->id,
            'ip_addr' => (string) $dec,
            'mac_address_current' => 'aa:bb:cc:dd:ee:ff',
        ]);
    }

    public function test_discovery_deep_updates_ports_and_links()
    {
        $customer = Customer::factory()->create();
        $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);
        // Setup two devices: a remote device with a port (mac), and a local device
        $model = DeviceModel::create(['brand' => 'Acme', 'model' => 'M-remote', 'type' => 'switch']);
        $remote = Device::create([
            'hostname' => 'remote',
            'device_model_id' => $model->id,
            'customer_id' => $customer->id,
            'scan_agent_id' => $agent->id,
        ]);
        $remotePort = \App\Models\Port::create(['device_id' => $remote->id, 'name' => 'ether1', 'mac_address' => '11:22:33:44:55:66']);

        $device = Device::create([
            'hostname' => 'local',
            'device_model_id' => $model->id,
            'customer_id' => $customer->id,
            'scan_agent_id' => $agent->id,
        ]);

        $job = AgentJob::create([
            'device_id' => $device->id,
            'scan_agent_id' => $agent->id,
            'type' => 'discovery_deep',
                'status' => 'completed',
            'payload' => [],
            'result' => [
                'interfaces' => [
                    ['name' => 'ether2', 'mac-address' => 'aa:11:11:11:11:11', 'running' => true],
                ],
                'bridge_hosts' => [
                    ['mac-address' => '11:22:33:44:55:66', 'interface' => 'ether2', 'local' => false],
                ],
                'ip_neighbors' => [
                    ['mac-address' => '11:22:33:44:55:66', 'interface' => 'ether2', 'address' => '10.0.0.2'],
                ],
            ],
        ]);

        app(ProcessAgentJobResultAction::class)->execute($job);

        // Local port should exist and be linked to remote port
        $localPort = \App\Models\Port::where('device_id', $device->id)->where('name', 'ether2')->first();

        $this->assertNotNull($localPort);
        $this->assertEquals($remotePort->id, $localPort->connected_to_port_id);
    }

    public function test_discovery_deep_ingests_topology_before_arp_to_create_vlan_and_subnet()
    {
        $customer = Customer::factory()->create();
        $agent = ScanAgent::factory()->create(['customer_id' => $customer->id]);
        $model = DeviceModel::create(['brand' => 'Acme', 'model' => 'M-switch', 'type' => 'switch']);
        $device = Device::create(['hostname' => 'edge', 'device_model_id' => $model->id, 'customer_id' => $customer->id, 'scan_agent_id' => $agent->id]);

        $job = AgentJob::create([
            'device_id' => $device->id,
            'scan_agent_id' => $agent->id,
            'type' => 'discovery_deep',
            'status' => 'completed',
            'payload' => [],
            'result' => [
                'interfaces' => [
                    ['name' => 'ether1', 'running' => true],
                ],
                'bridge_vlan' => [
                    ['vlan-ids' => '10', 'untagged' => ['ether1']],
                ],
                'arp_table' => [
                    ['address' => '10.10.10.5', 'mac-address' => 'aa:bb:cc:dd:ee:ff', 'interface' => 'ether1'],
                ],
            ],
        ]);

        $this->assertNotEmpty($job->result);

        app(ProcessAgentJobResultAction::class)->execute($job);

        $converter = app(IpConversionService::class);

        $this->assertDatabaseHas('vlans', ['number' => 10, 'customer_id' => $device->customer_id]);

        $this->assertDatabaseHas('subnets', [
            'customer_id' => $device->customer_id,
            'mask' => 24,
        ]);

        $this->assertDatabaseHas('ip_addresses', [
            'ip_addr' => (string) $converter->toDecimal('10.10.10.5'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);
    }
}

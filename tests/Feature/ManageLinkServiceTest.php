<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CableType;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Port;
use App\Services\Physical\ManageLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManageLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_creates_link_and_sets_port_statuses(): void
    {
        $model = DeviceModel::create(['brand' => 'Acme', 'model' => 'S', 'type' => 'switch', 'u_size' => 1, 'ethernet_ports' => 2]);

        $deviceA = Device::create(['hostname' => 'a', 'device_model_id' => $model->id]);
        $deviceB = Device::create(['hostname' => 'b', 'device_model_id' => $model->id]);

        $pA = Port::create(['device_id' => $deviceA->id, 'name' => 'Eth1', 'status' => 'down']);
        $pB = Port::create(['device_id' => $deviceB->id, 'name' => 'Eth1', 'status' => 'down']);

        $service = new ManageLinkService();

        $cable = CableType::create(['name' => 'Cat6', 'category' => 'cat6', 'color' => '#000000']);

        $link = $service->connect($pA, $pB, $cable->id);

        $this->assertDatabaseHas('links', ['id' => $link->id]);

        $pA->refresh();
        $pB->refresh();

        $this->assertSame('active', $pA->status);
        $this->assertSame('active', $pB->status);
        $this->assertSame($pB->id, $pA->connected_to_port_id);
        $this->assertSame($pA->id, $pB->connected_to_port_id);
    }

    public function test_connect_fails_if_ports_not_down(): void
    {
        $model = DeviceModel::create(['brand' => 'Acme', 'model' => 'S', 'type' => 'switch', 'u_size' => 1]);

        $deviceA = Device::create(['hostname' => 'a', 'device_model_id' => $model->id]);
        $deviceB = Device::create(['hostname' => 'b', 'device_model_id' => $model->id]);

        $pA = Port::create(['device_id' => $deviceA->id, 'name' => 'Eth1', 'status' => 'active']);
        $pB = Port::create(['device_id' => $deviceB->id, 'name' => 'Eth1', 'status' => 'down']);

        $service = new ManageLinkService();

        $this->expectException(\RuntimeException::class);

        $service->connect($pA, $pB, null);
    }

    public function test_disconnect_deletes_link_and_resets_ports(): void
    {
        $model = DeviceModel::create(['brand' => 'Acme', 'model' => 'S', 'type' => 'switch', 'u_size' => 1]);

        $deviceA = Device::create(['hostname' => 'a', 'device_model_id' => $model->id]);
        $deviceB = Device::create(['hostname' => 'b', 'device_model_id' => $model->id]);

        $pA = Port::create(['device_id' => $deviceA->id, 'name' => 'Eth1', 'status' => 'active']);
        $pB = Port::create(['device_id' => $deviceB->id, 'name' => 'Eth1', 'status' => 'active']);

        $link = \App\Models\Link::create(['port_a_id' => $pA->id, 'port_b_id' => $pB->id]);

        // mark connected ids
        $pA->update(['connected_to_port_id' => $pB->id]);
        $pB->update(['connected_to_port_id' => $pA->id]);

        $service = new ManageLinkService();

        $service->disconnect($pA);

        $this->assertDatabaseMissing('links', ['id' => $link->id]);

        $pA->refresh();
        $pB->refresh();

        $this->assertSame('down', $pA->status);
        $this->assertSame('down', $pB->status);
        $this->assertNull($pA->connected_to_port_id);
        $this->assertNull($pB->connected_to_port_id);
    }
}

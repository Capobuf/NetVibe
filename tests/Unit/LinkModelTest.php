<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Link;
use App\Models\Port;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_prevents_self_link(): void
    {
        $model = DeviceModel::create(['brand' => 'X', 'model' => 'M', 'type' => 'switch', 'u_size' => 1]);
        $device = Device::create(['hostname' => 'a', 'device_model_id' => $model->id]);
        $p = Port::create(['device_id' => $device->id, 'name' => 'Eth1', 'status' => 'down']);

        $this->expectException(\RuntimeException::class);

        Link::create(['port_a_id' => $p->id, 'port_b_id' => $p->id]);
    }

    public function test_prevents_duplicate_unordered_link(): void
    {
        $model = DeviceModel::create(['brand' => 'X', 'model' => 'M', 'type' => 'switch', 'u_size' => 1]);
        $a = Device::create(['hostname' => 'a', 'device_model_id' => $model->id]);
        $b = Device::create(['hostname' => 'b', 'device_model_id' => $model->id]);

        $pA = Port::create(['device_id' => $a->id, 'name' => 'Eth1', 'status' => 'down']);
        $pB = Port::create(['device_id' => $b->id, 'name' => 'Eth1', 'status' => 'down']);

        Link::create(['port_a_id' => $pA->id, 'port_b_id' => $pB->id]);

        // attempt to create reverse order; should raise
        $this->expectException(\RuntimeException::class);

        Link::create(['port_a_id' => $pB->id, 'port_b_id' => $pA->id]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\DeviceModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceModelQuickCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_from_quick_create_accepts_backed_enum_value()
    {
        $data = [
            'brand' => 'Acme',
            'model' => 'Q-1U',
            'type' => 'switch', // backed enum value
            'u_size' => 1,
            'ethernet_ports' => 24,
            'sfp_ports' => 2,
        ];

        $id = DeviceModel::createFromQuickCreate($data);

        $this->assertIsInt($id);
        $this->assertDatabaseHas('device_models', ['id' => $id, 'brand' => 'Acme', 'model' => 'Q-1U', 'type' => 'switch']);
    }

    public function test_create_from_quick_create_accepts_label_and_normalizes_type()
    {
        $data = [
            'brand' => 'Acme',
            'model' => 'Q-2U',
            'type' => 'Switch', // label form (case-insensitive)
            'u_size' => 2,
            'ethernet_ports' => 48,
            'sfp_ports' => 4,
        ];

        $id = DeviceModel::createFromQuickCreate($data);

        $this->assertIsInt($id);
        $this->assertDatabaseHas('device_models', ['id' => $id, 'brand' => 'Acme', 'model' => 'Q-2U', 'type' => 'switch']);
    }
}

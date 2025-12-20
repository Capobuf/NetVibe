<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Customer;
use App\Models\Location;
use App\Models\DeviceModel;
use App\Models\Rack;
use App\Models\Device;
use App\Enums\DeviceType;

class DeviceCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_can_be_created_and_linked_to_rack(): void
    {
        // Create a minimal customer and location
        $customer = Customer::create(['name' => 'Acme']);

        $location = Location::create([
            'customer_id' => $customer->id,
            'name' => 'Main DC',
            'address' => 'Test address',
        ]);

        // Create device model
        $deviceModel = DeviceModel::create([
            'brand' => 'ACME',
            'model' => 'Edge-1',
            'type' => DeviceType::Router->value,
            'u_size' => 1,
            'ethernet_ports' => 4,
            'sfp_ports' => 2,
            'description' => 'Test model',
        ]);

        // Create a rack
        $rack = Rack::create([
            'location_id' => $location->id,
            'name' => 'Rack 42',
            'units' => 42,
        ]);

        // Create a device linked to rack and device model
        $device = Device::create([
            'rack_id' => $rack->id,
            'device_model_id' => $deviceModel->id,
            'hostname' => 'edge-1.example.com',
            'serial_number' => 'SN-1234',
            'rack_position' => 10,
        ]);

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'hostname' => 'edge-1.example.com']);
        $this->assertEquals($rack->id, $device->rack->id);
        $this->assertEquals($deviceModel->id, $device->deviceModel->id);
    }

    public function test_monitored_device_can_be_created_without_model_when_api_credentials_provided(): void
    {
        $customer = Customer::create(['name' => 'Auto']);
        $rack = Rack::create(['name' => 'R', 'units' => 42]);

        // Simulate monitored device with Mikrotik credentials but no model yet
        $device = Device::create([
            'customer_id' => $customer->id,
            'rack_id' => $rack->id,
            'is_monitored' => true,
            'auth_driver' => 'mikrotik_rest',
            'auth_username' => 'admin',
            'auth_password' => 'secret',
            'ip_addr' => '10.0.0.10',
        ]);

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'device_model_id' => null,
            'is_monitored' => 1,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Clusters\Racks\Resources\Racks\RackResource;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Rack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RackVisualTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_units_positions_and_spans(): void
    {
        // create device models
        $modelA = DeviceModel::create([
            'brand' => 'Acme',
            'model' => 'A-2U',
            'type' => 'server',
            'u_size' => 2,
        ]);

        $modelB = DeviceModel::create([
            'brand' => 'Foo',
            'model' => 'B-1U',
            'type' => 'switch',
            'u_size' => 1,
        ]);

        // Create a customer + location because Rack requires location_id
        $customer = \App\Models\Customer::create(['name' => 'ACME Inc.']);
        $location = \App\Models\Location::create(['customer_id' => $customer->id, 'name' => 'HQ']);

        $rack = Rack::create(['name' => 'Test Rack', 'location_id' => $location->id]);

        // Device at position 10, size 2
        $dev1 = Device::create([
            'hostname' => 'dev1',
            'device_model_id' => $modelA->id,
            'rack_id' => $rack->id,
            'rack_position' => 10,
        ]);

        // Device at position 12, size 1
        $dev2 = Device::create([
            'hostname' => 'dev2',
            'device_model_id' => $modelB->id,
            'rack_id' => $rack->id,
            'rack_position' => 12,
        ]);

        $units = RackResource::buildUnits($rack);

        // Verify device at 10
        $this->assertArrayHasKey(10, $units);
        $this->assertSame('device', $units[10]['type']);
        $this->assertSame(2, $units[10]['span']);
        $this->assertSame($dev1->id, $units[10]['device']->id);

        // The U above (11) should be 'ghost' (occupied by the spanning device)
        $this->assertArrayHasKey(11, $units);
        $this->assertSame('ghost', $units[11]['type']);

        // Device at 12
        $this->assertArrayHasKey(12, $units);
        $this->assertSame('device', $units[12]['type']);
        $this->assertSame(1, $units[12]['span']);
        $this->assertSame($dev2->id, $units[12]['device']->id);
    }
}

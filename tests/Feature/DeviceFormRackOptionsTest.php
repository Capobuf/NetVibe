<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DeviceFormRackOptionsTest extends TestCase
{
    public function test_deviceform_contains_customer_and_location_filters()
    {
        $path = app_path('Filament/Clusters/Racks/Resources/Devices/Schemas/DeviceForm.php');
        $this->assertFileExists($path);

        $file = file_get_contents($path);

        // location filter should still be present
        $this->assertStringContainsString("->where('location_id',", $file);

        // new clause should consider rack.customer_id when a customer is selected
        $this->assertStringContainsString("->where('customer_id',", $file);

        // ensure default rack_id inclusion is present
        $this->assertStringContainsString("->orWhere('id', \$defaultRackId)", $file);
    }

    public function test_deviceform_has_request_query_default_for_rack_id()
    {
        $path = app_path('Filament/Clusters/Racks/Resources/Devices/Schemas/DeviceForm.php');
        $file = file_get_contents($path);

        $this->assertStringContainsString("default(request()->query('rack_id'))", $file);
    }

    public function test_deviceform_resets_rack_when_location_changes()
    {
        $path = app_path('Filament/Clusters/Racks/Resources/Devices/Schemas/DeviceForm.php');
        $file = file_get_contents($path);

        $this->assertStringContainsString("afterStateUpdated(fn (\$set) => \$set('rack_id', null))", $file);
    }
}

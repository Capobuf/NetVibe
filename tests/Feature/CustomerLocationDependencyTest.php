<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class CustomerLocationDependencyTest extends TestCase
{
    public function test_rackform_resets_location_on_customer_change_and_filters_locations()
    {
        $file = file_get_contents(app_path('Filament/Clusters/Racks/Resources/Racks/Schemas/RackForm.php'));
        $this->assertStringContainsString("->afterStateUpdated(fn (\$set) => \$set('location_id', null))", $file, 'RackForm customer select should reset location_id on change');
        $this->assertStringContainsString("->where('customer_id', ", $file, 'RackForm location options should filter by customer_id');
    }

    public function test_deviceform_location_filters_by_parent_customer_or_filter()
    {
        $file = file_get_contents(app_path('Filament/Clusters/Racks/Resources/Devices/Schemas/DeviceForm.php'));
        $this->assertStringContainsString("modifyQueryUsing", $file, 'DeviceForm location_id should use modifyQueryUsing to filter by customer');
        $this->assertStringContainsString('->disabled(fn ($get)', $file, 'DeviceForm location_id should be disabled until customer or location_filter is set');
        $this->assertStringContainsString("\$get('customer_id') || \$get('location_filter_id')", $file, 'DeviceForm location_id should reference both customer_id and location_filter_id in its disabled rule');
    }

    public function test_ipaddress_resource_filters_locations_by_customer_and_resets_on_change()
    {
        $file = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/IpAddresses/IpAddressResource.php'));
        $this->assertStringContainsString("modifyQueryUsing", $file, 'IpAddressResource location relationship should filter by customer');
        $this->assertStringContainsString("->afterStateUpdated(fn (\$set) => \$set('location_id', null))", $file, 'IpAddressResource customer select should reset location');
    }
}

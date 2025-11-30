<?php

namespace Tests\Feature;

use Tests\TestCase;

class SubnetResourceUiTest extends TestCase
{
    public function test_subnet_resource_has_vlan_field_in_form()
    {
        $file = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php'));
        $this->assertStringContainsString("Select::make('vlan_id')", $file, 'SubnetResource form should include a vlan_id Select field');
    }

    public function test_subnet_resource_has_create_header_action()
    {
        $file = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php'));
        $this->assertStringContainsString('CreateAction::make()', $file, 'SubnetResource table should register CreateAction in headerActions');
    }

    public function test_ip_addresses_relation_manager_exports_table_and_form()
    {
        $file = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/RelationManagers/IpAddressesRelationManager.php'));
        $this->assertStringContainsString("protected static string \$relationship = 'ipAddresses'", $file, 'Relation manager must declare relationship');
        // Filament RelationManager methods are instance methods (non-static)
        $this->assertStringContainsString('public function table(', $file, 'Relation manager should implement table()');
        $this->assertStringContainsString('public function form(', $file, 'Relation manager should implement form()');

        // Ensure gateway logic uses 'type' column (enum) instead of a non-existent is_gateway boolean
        $subnetFile = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php'));
        $this->assertStringContainsString("where('type', 'gateway')", $subnetFile, 'SubnetResource gateway lookup should query type = gateway');

        // Ensure IpAddresses relation manager uses type select instead of is_gateway toggle
        $this->assertStringContainsString("Select::make('type')", $file, 'Relation manager should use Select::make("type") for IP type');

        // Ensure IpAddress resource uses Select::make('type') and table shows the type badge
        $addrFile = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/IpAddresses/IpAddressResource.php'));
        $this->assertStringContainsString("Select::make('type')", $addrFile, 'IpAddressResource should use Select::make("type")');
        $this->assertStringNotContainsString('is_gateway', $addrFile, 'IpAddressResource should not reference is_gateway');

        // Confirm the Subnet view now uses the unified IpAddressesPanel widget
        $viewFile = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/Pages/ViewSubnet.php'));
        $this->assertStringContainsString('IpAddressVisualGrid::class', $viewFile, 'ViewSubnet should include IpAddressVisualGrid::class in footer widgets');
    }
}

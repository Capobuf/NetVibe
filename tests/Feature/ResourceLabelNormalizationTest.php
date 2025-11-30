<?php

namespace Tests\Feature;

use Tests\TestCase;

class ResourceLabelNormalizationTest extends TestCase
{
    public function test_vlan_and_l2domain_resources_have_normalized_labels()
    {
        $vlan = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Vlans/VlanResource.php'));
        $this->assertStringContainsString('protected static ?string $navigationLabel = \'VLANs\'', $vlan);
        $this->assertStringContainsString('protected static ?string $modelLabel = \'VLAN\'', $vlan);
        // Ensure VLANs DO NOT expose Delete in table record actions (matches CRUD UX policy)
        $this->assertStringNotContainsString('DeleteAction::make()', $vlan);

        // Ensure VLAN edit page exposes Delete with confirmation modal
        $editVlan = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Vlans/Pages/EditVlan.php'));
        $this->assertStringContainsString('DeleteAction::make()', $editVlan);
        $this->assertStringContainsString("modalHeading('Delete VLAN')", $editVlan);
        // Ensure Vlan resource exposes edit page route
        $this->assertStringContainsString("'edit' => Pages\\EditVlan::route('/{record}/edit')", $vlan);

        $l2 = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/L2Domains/L2DomainResource.php'));
        $this->assertStringContainsString('protected static ?string $navigationLabel = \'L2 Domains\'', $l2);
        $this->assertStringContainsString('protected static ?string $modelLabel = \'L2 Domain\'', $l2);
        // Ensure Subnet resource exposes edit page route (Subnet check placed here for convenience)
        $subnet = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php'));
        $this->assertStringContainsString("'edit' => Pages\\EditSubnet::route('/{record}/edit')", $subnet);
    }
}

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

        $l2 = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/L2Domains/L2DomainResource.php'));
        $this->assertStringContainsString('protected static ?string $navigationLabel = \'L2 Domains\'', $l2);
        $this->assertStringContainsString('protected static ?string $modelLabel = \'L2 Domain\'', $l2);
    }
}

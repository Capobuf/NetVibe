<?php

namespace Tests\Feature;

use Tests\TestCase;

class SubnetRelationManagerUiTest extends TestCase
{
    public function test_relation_manager_title_hidden_to_prevent_duplicate_headings()
    {
        $file = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/RelationManagers/IpAddressesRelationManager.php'));
        $this->assertStringContainsString('protected static ?string $title = null', $file, 'Relation manager should hide its title to avoid duplicate headers');
    }
}

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

    public function test_relation_manager_has_create_action_in_header()
    {
        $file = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/RelationManagers/IpAddressesRelationManager.php'));
        $this->assertStringContainsString("CreateAction::make('create_ip_header')", $file, 'Relation manager should have a create action in the header');
        $this->assertStringContainsString("->headerActions([", $file, 'Relation manager should have header actions defined');
    }

    public function test_relation_manager_is_not_read_only()
    {
        $manager = new \App\Filament\Clusters\Ipam\Resources\Subnets\RelationManagers\IpAddressesRelationManager(
            new \Filament\Resources\RelationManagers\RelationManagerConfiguration(
                \App\Filament\Clusters\Ipam\Resources\Subnets\RelationManagers\IpAddressesRelationManager::class
            )
        );
        $this->assertFalse($manager->isReadOnly(), 'Relation manager should explicitly disable read-only mode to allow creation on View pages');
    }
}

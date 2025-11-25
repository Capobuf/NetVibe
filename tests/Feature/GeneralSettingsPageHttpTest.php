<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Settings\GeneralSettings;

class GeneralSettingsPageHttpTest extends TestCase
{
    public function test_general_settings_page_class_and_view_exist()
    {
        $this->assertTrue(class_exists(\App\Filament\Pages\GeneralSettingsPage::class));

        $viewPath = resource_path('views/filament/pages/general-settings.blade.php');
        $this->assertFileExists($viewPath);
    }

    public function test_navigation_properties_are_set()
    {
        $page = new \App\Filament\Pages\GeneralSettingsPage();

        // navigation label should be set to a single unified 'Settings' entry and the group should be null (isolated at bottom)
        $this->assertSame('Settings', $page::getNavigationLabel());
        $this->assertNull($page::getNavigationGroup());
    }
}

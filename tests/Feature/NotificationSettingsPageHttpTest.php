<?php

namespace Tests\Feature;

use Tests\TestCase;

class NotificationSettingsPageHttpTest extends TestCase
{
    public function test_notification_settings_page_class_and_view_exist()
    {
        $this->assertTrue(class_exists(\App\Filament\Pages\NotificationSettingsPage::class));

        $viewPath = resource_path('views/filament/pages/notification-settings.blade.php');
        $this->assertFileExists($viewPath);

        $contents = file_get_contents($viewPath);
        $this->assertStringContainsString('<x-filament::card', $contents);
    }

    public function test_navigation_properties_are_set()
    {
        $page = new \App\Filament\Pages\NotificationSettingsPage();

        $this->assertSame('Notifications', $page::getNavigationLabel());
        $this->assertSame('Administration', $page::getNavigationGroup());
    }
}

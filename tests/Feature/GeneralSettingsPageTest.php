<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Settings\GeneralSettings;

class GeneralSettingsPageTest extends TestCase
{
    public function test_general_settings_page_saves_settings()
    {
        // Ensure the settings table exists in test DB (spatie package may query it during boot)
        // Only create the table if it does not already exist. This avoids conflicts when
        // the real package migration is present and the test environment runs migrations.
        if (! \Illuminate\Support\Facades\Schema::hasTable('settings')) {
            \Illuminate\Support\Facades\Schema::create('settings', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('group');
            $table->string('name');
            $table->json('payload')->nullable();
            $table->timestamps();
            });
        }

        // Bind a fake settings instance so tests don't try to hit DB during assert/save
        $fakeSettings = new class extends GeneralSettings {
            public bool $saved = false;

            public function save(): \Spatie\LaravelSettings\Settings
            {
                // just mark saved, avoid DB calls in tests
                $this->saved = true;
                return $this;
            }
        };

        app()->instance(GeneralSettings::class, $fakeSettings);

        $page = new \App\Filament\Pages\GeneralSettingsPage();
        // don't call mount() to avoid the package reading from the real settings table
        $page->form->fill([
            'site_name' => 'New Name',
            'site_color' => '#abcdef',
        ]);

        $page->save();

        $saved = app(GeneralSettings::class);
        $this->assertSame('New Name', $saved->site_name);
        $this->assertFalse(property_exists($saved, 'scan_ping_timeout'));
        $this->assertSame('#abcdef', $saved->site_color);
        $this->assertTrue($saved->saved);
    }
}

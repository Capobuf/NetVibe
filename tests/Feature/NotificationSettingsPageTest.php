<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Settings\NotificationSettings;

class NotificationSettingsPageTest extends TestCase
{
    public function test_notification_settings_page_saves_settings()
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('settings')) {
            \Illuminate\Support\Facades\Schema::create('settings', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->string('group');
                $table->string('name');
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        $fakeSettings = new class extends NotificationSettings {
            public bool $saved = false;

            public function save(): \Spatie\LaravelSettings\Settings
            {
                $this->saved = true;
                return $this;
            }
        };

        app()->instance(NotificationSettings::class, $fakeSettings);

        $page = new \App\Filament\Pages\NotificationSettingsPage();

        $page->form->fill([
            'webhook_enabled' => true,
            'webhook_url' => 'https://example.com/hook',
            'rate_limit_seconds' => 120,
        ]);

        $page->save();

        $saved = app(NotificationSettings::class);
        $this->assertTrue($saved->webhook_enabled);
        $this->assertSame('https://example.com/hook', $saved->webhook_url);
        $this->assertSame(120, $saved->rate_limit_seconds);
        $this->assertTrue($saved->saved);
    }
}

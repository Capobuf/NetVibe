<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Settings\SystemStateSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupWizardFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_setup_when_not_installed(): void
    {
        /** @var SystemStateSettings $state */
        $state = app(SystemStateSettings::class);
        $state->installed = false;
        $state->configured = false;
        $state->save();

        $response = $this->get('/');

        $response->assertRedirect('/setup');
    }

    public function test_setup_flow_marks_system_as_configured(): void
    {
        // ensure fresh state
        /** @var SystemStateSettings $state */
        $state = app(SystemStateSettings::class);
        $state->installed = false;
        $state->configured = false;
        $state->save();

        // simulate migrate step
        $this->post(route('setup.migrate.run'));

        // create admin user
        $this->post(route('setup.user.store'), [
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        // create first customer/location
        $this->post(route('setup.customer.store'), [
            'customer_name' => 'Test Customer',
            'location_name' => 'HQ',
            'location_address' => 'Main street',
        ]);

        // store app config
        $response = $this->post(route('setup.app.store'), [
            'app_url' => 'http://netvibe.test',
            'locale' => 'en',
        ]);

        $response->assertRedirect('/');

        $state = app(SystemStateSettings::class);
        $this->assertTrue($state->installed);
        $this->assertTrue($state->configured);
    }
}


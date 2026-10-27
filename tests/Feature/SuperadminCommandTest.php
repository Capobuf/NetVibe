<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperadminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_and_updates_the_same_account_from_configuration(): void
    {
        config(['superadmin' => ['email' => 'owner@example.com', 'password' => 'first-password']]);

        $this->artisan('netvibe:superadmin')->assertSuccessful();

        $user = User::sole();
        $this->assertTrue($user->is_superadmin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('first-password', $user->password));

        config(['superadmin' => ['email' => 'new-owner@example.com', 'password' => 'second-password']]);

        $this->artisan('netvibe:superadmin')->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'new-owner@example.com']);
        $this->assertFalse(Hash::check('first-password', $user->fresh()->password));

        $this->post(route('login.store'), [
            'email' => 'new-owner@example.com',
            'password' => 'second-password',
        ])->assertRedirect(route('companies.index', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->get(route('companies.index'))->assertOk();
    }

    public function test_command_does_not_overwrite_or_promote_an_existing_user(): void
    {
        $user = User::factory()->create();
        config(['superadmin' => ['email' => $user->email, 'password' => 'another-password']]);

        $this->artisan('netvibe:superadmin')->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $this->assertFalse($user->fresh()->is_superadmin);
        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_command_requires_configured_credentials(): void
    {
        config(['superadmin' => ['email' => null, 'password' => null]]);

        $this->artisan('netvibe:superadmin')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}

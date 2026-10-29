<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_guests_to_login(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('login'));
    }

    public function test_home_redirects_superadmins_to_companies(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));

        $this->get(route('home'))->assertRedirect(route('companies.index'));
    }

    public function test_home_redirects_company_users_to_companies(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => false]));

        $this->get(route('home'))->assertRedirect(route('companies.index'));
    }

    public function test_home_requires_email_verification(): void
    {
        $this->actingAs(User::factory()->unverified()->create());

        $this->get(route('home'))->assertRedirect(route('verification.notice'));
    }
}

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

    public function test_home_redirects_authenticated_users_to_companies(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('home'))->assertRedirect('/companies');
    }

    public function test_home_requires_email_verification(): void
    {
        $this->actingAs(User::factory()->unverified()->create());

        $this->get(route('home'))->assertRedirect(route('verification.notice'));
    }
}

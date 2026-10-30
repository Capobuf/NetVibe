<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HardwareEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_superadmins_can_open_the_hardware_editor(): void
    {
        $this->get(route('administration.hardware-editor'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('administration.hardware-editor'))->assertForbidden();

        $this->actingAs(User::factory()->create(['is_superadmin' => true]))
            ->get(route('administration.hardware-editor'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('administration/hardware-editor'));
    }
}

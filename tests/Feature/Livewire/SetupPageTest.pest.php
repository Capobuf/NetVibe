<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use App\Livewire\SetupPage;
use App\Models\User;
use App\Services\EnvEditor;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Minimal replacement for the health check dependency
    $this->app->instance(\App\Services\SystemHealthCheckService::class, new class {
        public function checkAll(): array { return []; }
    });
});

it('guest sees setup when no user exists', function () {
    $this->get('/setup')->assertStatus(200);
});

it('redirects to admin when user exists', function () {
    User::factory()->create();

    $this->get('/setup')->assertRedirect('/admin');
});

it('wizard happy path creates admin and settings table on MariaDB', function () {
    // Bind EnvEditor mock to prevent .env modifications
    $editor = Mockery::mock(EnvEditor::class);
    $editor->shouldReceive('setKeys')->once()->andReturnTrue();
    $this->app->instance(EnvEditor::class, $editor);

    Livewire::test(SetupPage::class)
        ->set('db_host', env('DB_HOST'))
        ->set('db_port', env('DB_PORT'))
        ->set('db_database', env('DB_DATABASE'))
        ->set('db_username', env('DB_USERNAME'))
        ->set('db_password', env('DB_PASSWORD'))
        ->set('user_name', 'Admin')
        ->set('user_email', 'admin@example.test')
        ->set('password', 'secretpassword')
        ->set('password_confirmation', 'secretpassword')
        ->call('finishSetup')
        ->assertSet('setupCompleted', true);

    $this->assertDatabaseHas('users', ['email' => 'admin@example.test']);
    expect(Schema::hasTable('settings'))->toBeTrue();
});

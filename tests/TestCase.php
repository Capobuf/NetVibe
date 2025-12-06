<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    // Optionally configure environment for tests. Kept empty so phpunit.xml env vars
    // control the DB connection (allows switching between sqlite/mysql in CI).
    protected function getEnvironmentSetUp($app)
    {
        // Ensure tests use the dedicated testing database (netvibe_testing) when running inside containers
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');

        // Respect environment-driven configuration for tests. phpunit.xml and the container
        // environment will provide DB_* variables when tests run inside Docker. We fallback to
        // the current environment values so nothing is hard-coded here.
        $app['config']->set('database.default', env('DB_CONNECTION', $app['config']->get('database.default')));
        $app['config']->set('database.connections.mysql.host', env('DB_HOST', $app['config']->get('database.connections.mysql.host')));
        $app['config']->set('database.connections.mysql.port', env('DB_PORT', $app['config']->get('database.connections.mysql.port')));
        $app['config']->set('database.connections.mysql.database', env('DB_DATABASE', $app['config']->get('database.connections.mysql.database')));
        $app['config']->set('database.connections.mysql.username', env('DB_USERNAME', $app['config']->get('database.connections.mysql.username')));
        $app['config']->set('database.connections.mysql.password', env('DB_PASSWORD', $app['config']->get('database.connections.mysql.password')));
    }

    // NUOVA FUNZIONE: Bootstrappa il Pannello Admin di Filament
    protected function bootFilament()
    {
        // Registra il provider del pannello per assicurare che Resources e Pages siano scoperti
        // Registering the provider with the application ensures Filament will discover Resources/Pages
        // in the test application lifecycle.
        $this->app->register(AdminPanelProvider::class);

        // Ensure the registered panel is booted so Filament discovers resources/pages/widgets
        Filament::bootCurrentPanel();
    }
}
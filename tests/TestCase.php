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

        // Force tests to use the dedicated testing database credentials
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.host', env('DB_HOST', '127.0.0.1'));
        $app['config']->set('database.connections.mysql.port', env('DB_PORT', 3306));
        $app['config']->set('database.connections.mysql.database', 'netvibe_testing');
        $app['config']->set('database.connections.mysql.username', 'netvibe');
        $app['config']->set('database.connections.mysql.password', 'netvibe');
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
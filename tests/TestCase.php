<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    // Aggiunto per forzare l'uso di SQLite in-memory, risolvendo i problemi di 'Permission denied' in Docker.
    protected function getEnvironmentSetUp($app)
    {
        // Rende la connessione DB e il database in-memory espliciti per il test environment.
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');

        // Ensure environment vars are consistent with the test sqlite configuration.
        // Some test helpers access DB_DATABASE from env directly when refreshing sqlite files.
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = ':memory:';
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
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Ensure the setup middleware runs for web routes so we can intercept and
        // redirect to the setup flow if necessary.
        $middleware->appendToGroup('web', [\App\Http\Middleware\EnsureAppIsInstalled::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

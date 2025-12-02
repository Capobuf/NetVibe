<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Configure trusted proxies from environment.
        // Accept comma-separated list in TRUSTED_PROXIES, fallback to '*'.
        $proxies = env('TRUSTED_PROXIES', '*');
        $middleware->trustProxies(
            at: $proxies,
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB
        );

        // Optionally force canonical host before other web middleware when enabled via
        // the FORCE_CANONICAL_URL env var. This redirect is useful when you want a
        // single public hostname (APP_URL) and to avoid serving the same app from
        // alternate hostnames (localhost, IPs, etc.). It is disabled by default.
        $middleware->appendToGroup('web', [\App\Http\Middleware\ForceCanonicalUrl::class, \App\Http\Middleware\EnsureAppIsInstalled::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

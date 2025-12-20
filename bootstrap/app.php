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
        // Configure trusted proxies from environment.
        // Accept comma-separated list in TRUSTED_PROXIES, fallback to '*'.
        $proxies = env('TRUSTED_PROXIES', '*');
        $proxies = $proxies === '*' ? '*' : array_filter(array_map('trim', explode(',', $proxies)));

        $middleware->trustProxies(
            at: $proxies,
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB
        );

        // Optionally force canonical host before other web middleware when enabled via
        // the FORCE_CANONICAL_URL env var. This redirect is useful when you want a
        // single public hostname (APP_URL) and to avoid serving the same app from
        // alternate hostnames (localhost, IPs, etc.). It is disabled by default.
        $middleware->appendToGroup('web', [
            \App\Http\Middleware\ForceCanonicalUrl::class,
            \App\Http\Middleware\CheckSystemInstalled::class,
        ]);

        // Ensure the auth middleware doesn't call route('login') for API
        // requests (this previously caused RouteNotFoundException -> 500)
        // when the named 'login' route doesn't exist. For API calls
        // return null so the middleware will produce a JSON 401 response
        // instead of attempting a redirect. For web requests if the
        // named route is missing we fall back to using '/login'.
        $middleware->redirectGuestsTo(function (Request $request) {
            // For API or JSON requests, avoid redirect->route('login') calls
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                return null;
            }

            // Prefer the named route when it exists, but don't call the
            // route() helper if it doesn't or it'll throw RouteNotFoundException.
            if (\Illuminate\Support\Facades\Route::has('login')) {
                return \route('login');
            }

            // Safe fallback URL when the named route is not present.
            return '/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Treat any request that touches the API or carries an Authorization
        // header as JSON for the purposes of exception rendering. This means
        // authentication failures will be returned as JSON 401 instead of
        // attempting a redirect to a missing `login` route (which produced
        // an HTML 500 earlier).
        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, \Throwable $e) {
            return $request->expectsJson()
                || $request->headers->has('Authorization')
                || str_contains($request->getRequestUri(), '/api/');
        });
    })->create();

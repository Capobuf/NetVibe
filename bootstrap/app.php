<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Notification failures must not leak SMTP replies or credentials into logs or debug pages.
        $exceptions->report(function (TransportExceptionInterface $exception): void {
            Log::warning('Invio email SMTP non riuscito.');
        })->stop();
        $exceptions->render(function (TransportExceptionInterface $exception, Request $request) {
            $message = 'Invio email non riuscito. Riprova più tardi o contatta l’amministratore.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 503);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back()->withErrors(['email' => $message]);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnsureAppIsInstalled
{
    /**
     * Paths that should always be allowed through, even during setup or DB errors.
     */
    private const EXCLUDED_PATHS = [
        'livewire/*',
        'filament/assets*',
        '_debugbar*',
        'session-debug', // Debug route
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Always allow excluded paths (Livewire, assets, debugbar) through
        if ($this->isExcludedPath($request)) {
            return $next($request);
        }

        try {
            // Check installation status first
            $installed = \App\Models\User::exists();

            // If already installed and trying to access /setup, redirect to /admin
            if ($installed && $request->is('setup')) {
                return redirect('/');
            }

            // If not installed and not accessing /setup, redirect to /setup
            if (! $installed && ! $request->is('setup')) {
                return redirect('/setup');
            }

            // Allow the request
            return $next($request);
        } catch (Throwable $e) {
            // If the DB is down or throws, forward the user to /setup so the wizard can guide
            Log::warning('EnsureAppIsInstalled middleware encountered an exception during User::exists(): '.$e->getMessage());
            
            if (! $request->is('setup')) {
                return redirect('/setup');
            }

            return $next($request);
        }
    }

    /**
     * Check if the request path matches any excluded patterns.
     */
    private function isExcludedPath(Request $request): bool
    {
        foreach (self::EXCLUDED_PATHS as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}

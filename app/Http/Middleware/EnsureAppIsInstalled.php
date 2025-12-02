<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnsureAppIsInstalled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            // Check installation status first
            $installed = \App\Models\User::exists();

            // If already installed and trying to access /setup, redirect to /admin
            if ($installed && $request->is('setup')) {
                return redirect('/admin');
            }

            // If not installed and not accessing /setup, redirect to /setup
            // EXCEPT for excluded paths (livewire, assets, etc.)
            if (! $installed && ! $request->is('setup')) {
                $excluded = [
                    'livewire/*',
                    'filament/assets*',
                    '_debugbar*',
                ];

                foreach ($excluded as $pattern) {
                    if ($request->is($pattern)) {
                        return $next($request);
                    }
                }

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
}

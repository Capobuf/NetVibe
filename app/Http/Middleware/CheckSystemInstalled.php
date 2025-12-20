<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Settings\SystemStateSettings;
use Throwable;

class CheckSystemInstalled
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Always allow setup routes and assets
        if ($request->is('setup*') || $request->is('livewire/*') || $request->is('filament/assets*')) {
            // If accessing setup but system IS installed, redirect to home
            if ($request->is('setup*') && $this->isInstalled()) {
                return redirect('/');
            }
            return $next($request);
        }

        // For all other routes, if NOT installed, redirect to setup
        if (! $this->isInstalled()) {
            return redirect('/setup');
        }

        return $next($request);
    }

    /**
     * Check if the system is installed.
     */
    protected function isInstalled(): bool
    {
        try {
            // Fonte autorevole: stato di sistema persistito
            if (class_exists(SystemStateSettings::class)) {
                /** @var \App\Settings\SystemStateSettings $state */
                $state = app(SystemStateSettings::class);

                return $state->installed && $state->configured;
            }

            // Fallback legacy solo quando SystemStateSettings non è disponibile
            return Schema::hasTable('users') && User::exists();
        } catch (Throwable $e) {
            return false;
        }
    }
}

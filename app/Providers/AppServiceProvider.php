<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers
        if (class_exists(\App\Models\Subnet::class) && class_exists(\App\Observers\SubnetObserver::class)) {
            \App\Models\Subnet::observe(\App\Observers\SubnetObserver::class);
        }
    }
}

<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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

        // Explicitly register Livewire components for Filament widgets so component
        // discovery is reliable across deployments and environments that use
        // cached manifests / opcache. Register both the new panel name and the
        // legacy visual-grid name as an alias pointing to the new widget class.
        if (class_exists(\App\Filament\Clusters\Ipam\Resources\Subnets\Widgets\IpAddressVisualGrid::class)) {
            Livewire::component(
                'app.filament.clusters.ipam.resources.subnets.widgets.ip-address-visual-grid',
                \App\Filament\Clusters\Ipam\Resources\Subnets\Widgets\IpAddressVisualGrid::class,
            );
        }
    }
}

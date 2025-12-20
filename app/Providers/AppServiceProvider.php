<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Runtime tenant context store (used by model global scopes and ingestion jobs)
        $this->app->singleton(\App\Tenancy\TenantContextStore::class, fn () => new \App\Tenancy\TenantContextStore());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $appUrl = config('app.url');

        if ($appUrl) {
            // 1. Force Root URL (Routes/Links)
            \Illuminate\Support\Facades\URL::forceRootUrl($appUrl);

            // 2. Force HTTPS scheme if URL is https (Fixes Mixed Content)
            if (str_starts_with($appUrl, 'https://')) {
                \Illuminate\Support\Facades\URL::forceScheme('https');
            }

            // 3. Auto-set Asset URL if not explicitly set in .env (Fixes Vite/CSS)
            if (empty(config('app.asset_url'))) {
                config(['app.asset_url' => $appUrl]);
            }
        }

        // Register model observers
        if (class_exists(\App\Models\Subnet::class) && class_exists(\App\Observers\SubnetObserver::class)) {
            \App\Models\Subnet::observe(\App\Observers\SubnetObserver::class);
        }

        // Apply runtime URL settings from GeneralSettings. Wrap in try/catch
        // because during deploy or migrate the settings source (DB) may not
        // be available yet.
        try {
            $settings = app(\App\Settings\GeneralSettings::class);

            if (! empty($settings->app_url)) {
                \Illuminate\Support\Facades\URL::forceRootUrl($settings->app_url);
            }

            if (! empty($settings->force_https)) {
                \Illuminate\Support\Facades\URL::forceScheme('https');
            }
        } catch (\Throwable $e) {
            // ignore; this allows migrations and early bootstrap to succeed
        }

        // (no-op) removed boot-time debugging for render callbacks — kept
        // exception handling logic free of noisy debug logs.

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

<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
            function () {
                // Render the Livewire TenantSwitcher safely; if Livewire mount fails fall back to view
                    // Render the livewire component via Blade so the Livewire lifecycle (mount)
                    // runs correctly and the component can initialize from session on full page loads.
                    try {
                        return \Illuminate\Support\Facades\Blade::render("@livewire('tenant-switcher')");
                    } catch (\Throwable $e) {
                        // If Blade rendering fails, fallback to rendering the view directly with options
                        $options = \App\Models\Customer::query()->orderBy('name')->pluck('name', 'id')->toArray();
                        return view('livewire.tenant-switcher', ['options' => $options])->render();
                    }
            }
        );

        return $panel
            // Use top navigation for the admin panel (Filament v4)
            ->topNavigation()
            ->default()
            ->id('admin')
            ->path('/')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            // Also discover resources stored inside clusters (e.g. app/Filament/Clusters/Ipam/Resources)
            ->discoverResources(in: app_path('Filament/Clusters/Ipam/Resources'), for: 'App\\Filament\\Clusters\\Ipam\\Resources')
            // Explicitly define icons/labels for the top nav groups so the topbar shows meaningful icons
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make()
                    ->label('Racks & Devices')
                    ->icon('heroicon-o-server'),
                \Filament\Navigation\NavigationGroup::make()
                    ->label('Discovery')
                    ->icon('heroicon-o-magnifying-glass-circle'),
                \Filament\Navigation\NavigationGroup::make()
                    ->label('Organization')
                    ->icon('heroicon-o-building-office-2'),
                \Filament\Navigation\NavigationGroup::make()
                    ->label('IPAM')
                    ->icon('heroicon-o-rectangle-stack'),
                \Filament\Navigation\NavigationGroup::make()
                    ->label('Infrastructure')
                    ->icon('heroicon-o-wrench-screwdriver'),
            ])
            ->discoverResources(in: app_path('Filament/Clusters/Discovery/Resources'), for: 'App\\Filament\\Clusters\\Discovery\\Resources')
            ->discoverResources(in: app_path('Filament/Clusters/Organization/Resources'), for: 'App\\Filament\\Clusters\\Organization\\Resources')
            ->discoverResources(in: app_path('Filament/Clusters/Racks/Resources'), for: 'App\\Filament\\Clusters\\Racks\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                // AccountWidget::class,
                // FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                \App\Http\Middleware\CheckSystemInstalled::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->databaseNotifications();
    }
}

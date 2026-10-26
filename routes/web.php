<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // shortcut: operational access is shared by verified users until tenant permissions are implemented.
    Route::resource('companies', CompanyController::class)->only(['index', 'show']);
    Route::get('companies/{company}/sites/{site}', [CompanyController::class, 'show'])
        ->scopeBindings()->name('companies.sites.show');

    Route::prefix('administration')->name('administration.')->middleware('can:administer')->group(function () {
        Route::resource('companies', CompanyController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
        Route::resource('companies.sites', SiteController::class)->only(['store', 'update', 'destroy'])->scoped();
    });
});

require __DIR__.'/settings.php';

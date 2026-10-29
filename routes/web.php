<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ExcludedRangeController;
use App\Http\Controllers\IpAddressController;
use App\Http\Controllers\IpSearchController;
use App\Http\Controllers\IpStatusController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SubnetController;
use App\Http\Controllers\SubnetSummaryEntryController;
use App\Http\Controllers\VlanController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/companies')->name('dashboard');

    // shortcut: operational access is shared by verified users until tenant permissions are implemented.
    Route::redirect('search', '/companies');
    Route::resource('companies', CompanyController::class)->only(['index', 'show']);
    Route::get('companies/{company}/sites/{site}', [CompanyController::class, 'show'])
        ->scopeBindings()->name('companies.sites.show');

    Route::scopeBindings()->group(function () {
        Route::get('companies/{company}/sites/{site}/search', [IpSearchController::class, 'index'])
            ->name('companies.sites.search.index');
        Route::resource('companies.sites.subnets', SubnetController::class)->only(['index', 'show']);
        Route::resource('companies.sites.vlans', VlanController::class)->only(['index']);
    });

    Route::middleware('can:administer')->scopeBindings()->group(function () {
        Route::resource('companies.sites.subnets.summary-entries', SubnetSummaryEntryController::class)
            ->parameters(['summary-entries' => 'summaryEntry'])->only(['store', 'update', 'destroy']);
        Route::resource('companies.sites.subnets.excluded-ranges', ExcludedRangeController::class)
            ->parameters(['excluded-ranges' => 'excludedRange'])->only(['store', 'update']);
        Route::delete('companies/{company}/sites/{site}/subnets/{subnet}/excluded-ranges', [ExcludedRangeController::class, 'destroy'])
            ->name('companies.sites.subnets.excluded-ranges.destroy');
        Route::resource('companies.sites.subnets.ip-addresses', IpAddressController::class)
            ->parameters(['ip-addresses' => 'ipAddress'])->only(['store', 'update', 'destroy']);
        Route::resource('companies.sites.vlans', VlanController::class)->only(['store', 'update']);
        Route::resource('companies.sites.subnets', SubnetController::class)->only(['store', 'update']);
    });

    Route::prefix('administration')->name('administration.')->middleware('can:administer')->group(function () {
        Route::redirect('/', '/administration/companies')->name('index');
        Route::resource('ip-statuses', IpStatusController::class)
            ->parameters(['ip-statuses' => 'ipStatus'])->only(['index', 'store', 'update']);
        Route::resource('companies', CompanyController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
        Route::resource('companies.sites', SiteController::class)->only(['store', 'update', 'destroy'])->scoped();
    });
});

require __DIR__.'/settings.php';

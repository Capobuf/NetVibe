<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyUserController;
use App\Http\Controllers\ExcludedRangeController;
use App\Http\Controllers\IpAddressController;
use App\Http\Controllers\IpSearchController;
use App\Http\Controllers\IpStatusController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SmtpSettingController;
use App\Http\Controllers\SubnetController;
use App\Http\Controllers\SubnetSummaryEntryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VlanController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', fn () => to_route('companies.index'))->name('home');
    Route::redirect('dashboard', '/')->name('dashboard');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('search', '/companies');
    Route::resource('companies', CompanyController::class)->only(['index']);

    Route::middleware('can:access-company,company')->scopeBindings()->group(function () {
        Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
        Route::get('companies/{company}/settings', [CompanyController::class, 'show'])->name('companies.settings.show');
        Route::get('companies/{company}/sites/{site}/deletion-impact', [SiteController::class, 'deletionImpact'])->name('companies.sites.deletion-impact');
        Route::resource('companies.sites', SiteController::class)->only(['store', 'update', 'destroy']);
        Route::prefix('companies/{company}/settings')->name('companies.settings.')->middleware('can:administer')->group(function () {
            Route::resource('users', CompanyUserController::class)->only(['index', 'store', 'destroy']);
        });
        Route::get('companies/{company}/sites/{site}', [CompanyController::class, 'show'])
            ->scopeBindings()->name('companies.sites.show');

        Route::scopeBindings()->group(function () {
            Route::get('companies/{company}/sites/{site}/search', [IpSearchController::class, 'index'])
                ->name('companies.sites.search.index');
            Route::resource('companies.sites.subnets', SubnetController::class)->only(['index', 'show']);
            Route::resource('companies.sites.vlans', VlanController::class)->only(['index', 'show']);
        });

        Route::scopeBindings()->group(function () {
            Route::resource('companies.sites.subnets.summary-entries', SubnetSummaryEntryController::class)
                ->parameters(['summary-entries' => 'summaryEntry'])->only(['store', 'update', 'destroy']);
            Route::resource('companies.sites.subnets.excluded-ranges', ExcludedRangeController::class)
                ->parameters(['excluded-ranges' => 'excludedRange'])->only(['store', 'update']);
            Route::delete('companies/{company}/sites/{site}/subnets/{subnet}/excluded-ranges', [ExcludedRangeController::class, 'destroy'])
                ->name('companies.sites.subnets.excluded-ranges.destroy');
            Route::resource('companies.sites.subnets.ip-addresses', IpAddressController::class)
                ->parameters(['ip-addresses' => 'ipAddress'])->only(['store', 'update', 'destroy']);
            Route::get('companies/{company}/sites/{site}/vlans/{vlan}/deletion-impact', [VlanController::class, 'deletionImpact'])
                ->name('companies.sites.vlans.deletion-impact');
            Route::get('companies/{company}/sites/{site}/subnets/{subnet}/deletion-impact', [SubnetController::class, 'deletionImpact'])
                ->name('companies.sites.subnets.deletion-impact');
            Route::resource('companies.sites.vlans', VlanController::class)->only(['store', 'update', 'destroy']);
            Route::resource('companies.sites.subnets', SubnetController::class)->only(['store', 'update', 'destroy']);
        });
    });

    Route::prefix('administration')->name('administration.')->middleware('can:administer')->group(function () {
        Route::resource('users', UserController::class)->only(['index', 'store', 'destroy']);
        Route::get('email', [SmtpSettingController::class, 'edit'])->name('email.edit');
        Route::put('email', [SmtpSettingController::class, 'update'])->name('email.update');
        Route::post('email/test', [SmtpSettingController::class, 'sendTest'])->middleware('throttle:smtp-test')->name('email.test');
        Route::redirect('/', '/administration/companies')->name('index');
        Route::get('ip-statuses/{ipStatus}/deletion-impact', [IpStatusController::class, 'deletionImpact'])->name('ip-statuses.deletion-impact');
        Route::get('companies/{company}/deletion-impact', [CompanyController::class, 'deletionImpact'])->name('companies.deletion-impact');
        Route::get('companies/{company}/sites/{site}/deletion-impact', [SiteController::class, 'deletionImpact'])
            ->scopeBindings()->name('companies.sites.deletion-impact');
        Route::resource('ip-statuses', IpStatusController::class)
            ->parameters(['ip-statuses' => 'ipStatus'])->only(['index', 'store', 'update', 'destroy']);
        Route::resource('companies', CompanyController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
        Route::resource('companies.sites', SiteController::class)->only(['store', 'update', 'destroy'])->scoped();
    });
});

require __DIR__.'/settings.php';

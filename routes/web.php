<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SetupController;

Route::middleware('web')->group(function () {
    Route::prefix('setup')->name('setup.')->controller(SetupController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/migrate', 'showMigrate')->name('migrate');
        Route::post('/migrate', 'runMigrate')->name('migrate.run');
        Route::get('/user', 'showUser')->name('user');
        Route::post('/user', 'storeUser')->name('user.store');
    });
});

// =============================================================================
// DEBUG ROUTE - Remove in production
// =============================================================================


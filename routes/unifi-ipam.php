<?php

use App\Http\Controllers\UnifiIpamController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:access-company,company'])->group(function () {
    Route::put('companies/{company}/sites/{site}/unifi/observations/{observation}', [UnifiIpamController::class, 'update'])
        ->whereNumber('observation')->name('companies.sites.unifi.observations.update');
});

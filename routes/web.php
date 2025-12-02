<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // Redirect to admin panel if installed, otherwise middleware will redirect to setup
    return redirect('/admin');
});

use App\Livewire\SetupPage;

Route::get('/setup', SetupPage::class)->name('setup');

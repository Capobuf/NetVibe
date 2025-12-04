<?php

use Illuminate\Support\Facades\Route;



use App\Livewire\SetupPage;

Route::get('/setup', SetupPage::class)->name('setup');

// =============================================================================
// DEBUG ROUTE - Remove in production
// =============================================================================


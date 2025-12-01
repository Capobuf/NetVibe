<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Livewire\SetupPage;

Route::get('/setup', SetupPage::class);

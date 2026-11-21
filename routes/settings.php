<?php

use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('settings/preferences', [ProfileController::class, 'updatePreferences'])->name('preferences.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::inertia('settings/notifications', 'settings/notifications')->name('notifications.edit');
    Route::patch('settings/notifications', [NotificationController::class, 'preferences'])->name('notifications.preferences');
    Route::post('notifications/read', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::delete('notifications/read', [NotificationController::class, 'destroyRead'])->name('notifications.destroy-read');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
    Route::patch('notifications/{notification}', [NotificationController::class, 'mark'])->whereUuid('notification')->name('notifications.mark');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->whereUuid('notification')->name('notifications.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');

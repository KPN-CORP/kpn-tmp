<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Support\Landing;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * App shell — the entry point, the dashboard, and the cross-cutting bits that
 * belong to no single feature. Loaded inside the `auth` group in web.php.
 */

// The app's front door. Every sign-in lands here too — see App\Support\Landing.
Route::get('/', fn () => redirect()->route(Landing::ROUTE))->name('home');
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

// Renders the shared "coming soon" screen. The path and route name are already
// the real ones; only the controller is still missing.
Route::get('/profile', fn () => Inertia::render('Placeholder', ['title' => 'Profile']))->name('profile');

// --- In-app notifications ---
Route::prefix('notifications')->name('notifications.')->group(function () {
    Route::post('{notification}/read', [NotificationController::class, 'markRead'])->name('read');
    Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read_all');
});

<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Everything in the app requires a signed-in user.
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index']);
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /*
     * Placeholder destinations so every sidebar link resolves (and shows its
     * active state) while the real menus are still being built. Each renders a
     * simple stub page with its title. Replace these with real controllers as
     * features land.
     */
    $placeholders = [
        '/activity' => 'Activity',
        '/users' => 'Users',
        '/reports' => 'Reports',
        '/settings' => 'Settings',
        '/profile' => 'Profile',
    ];

    foreach ($placeholders as $path => $title) {
        Route::get($path, fn () => Inertia::render('Placeholder', [
            'title' => $title,
        ]));
    }
});

require __DIR__.'/auth.php';

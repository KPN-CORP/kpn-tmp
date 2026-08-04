<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\DevLoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // SSO (production entry point): external portal posts an encrypted payload.
    Route::get('sso/dbauth', [SsoController::class, 'dbauth'])->name('sso.dbauth');

    // Dev-login (local/QA employee impersonation, key-gated — see services.dev_login).
    Route::get('dev-login', [DevLoginController::class, 'create'])->name('dev.login');
    Route::post('dev-login', [DevLoginController::class, 'store'])->name('dev.login.store');
    Route::get('dev-login/employees', [DevLoginController::class, 'employees'])->name('dev.login.employees');
    Route::get('dev-login/employees/search', [DevLoginController::class, 'search'])->name('dev.login.search');
    Route::post('dev-login/employees', [DevLoginController::class, 'impersonate'])->name('dev.login.impersonate');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

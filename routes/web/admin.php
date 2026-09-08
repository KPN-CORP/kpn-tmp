<?php

use App\Http\Controllers\ApprovalSettingController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserGuideController;
use Illuminate\Support\Facades\Route;

/*
 * Administration — the user guide, roles & permissions, and the per-employee
 * approval chains.
 */

// --- User Guide (viewing is open to every signed-in user; managing is gated) ---
Route::prefix('user-guide')->name('user_guide.')->group(function () {
    Route::get('/', [UserGuideController::class, 'index'])->name('index');
    Route::get('{userGuide}/download', [UserGuideController::class, 'download'])->name('download');

    Route::middleware('permission:manage_user_guide')->group(function () {
        Route::post('/', [UserGuideController::class, 'store'])->name('store');
        Route::delete('{userGuide}', [UserGuideController::class, 'destroy'])->name('destroy');
    });
});

// --- Roles & permissions (Spatie) ---
Route::middleware('permission:view_admin_setting')
    ->prefix('admin/roles')->name('roles.')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->name('index');
        Route::post('/', [RoleController::class, 'store'])->name('store');
        Route::put('{role}', [RoleController::class, 'update'])->name('update');
        Route::delete('{role}', [RoleController::class, 'destroy'])->name('destroy');
    });

// --- Approval Layers (per-employee superior chains) ---
// The static paths stay above `{employeeId}`.
Route::middleware('permission:view_approval_setting')
    ->prefix('approval-setting')->name('approval.setting.')->group(function () {
        Route::get('/', [ApprovalSettingController::class, 'index'])->name('index');
        Route::get('employees', [ApprovalSettingController::class, 'searchEmployees'])->name('employees');
        Route::post('import', [ApprovalSettingController::class, 'import'])->name('import');
        Route::get('{employeeId}/history', [ApprovalSettingController::class, 'history'])->name('history');
        Route::put('{employeeId}', [ApprovalSettingController::class, 'update'])->name('update');
    });

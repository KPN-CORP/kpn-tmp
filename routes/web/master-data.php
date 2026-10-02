<?php

use App\Enums\MasterDataType;
use App\Http\Controllers\CompetencyController;
use App\Http\Controllers\CompetencyImplementationController;
use App\Http\Controllers\CompetencyTypeController;
use App\Http\Controllers\DevelopmentModelPackageController;
use App\Http\Controllers\IdpSettingController;
use App\Http\Controllers\MasterDataController;
use Illuminate\Support\Facades\Route;

/*
 * Master Data + IDP Settings — the shared master entities every IDP screen
 * reads. One permission per screen, covering the screen AND its writes:
 *
 *   Master Data   manage_competency_type · manage_competency · manage_master_implementation
 *   IDP Settings  manage_development_model · manage_master_training ·
 *                 manage_master_development · manage_review_tools
 *
 * Note the split between reads and writes: most master screens render from
 * their own GET route but write through the shared `/idp-setting/masters`
 * endpoints, keyed by `{type}` (each kind of master has its own table now, so
 * an id is only unique within a kind). Those endpoints admit any of the master
 * permissions here and are checked per kind in MasterDataController, since
 * which screen a write belongs to is only known from its `{type}`.
 */

// ------------------------------------------------------------------ Master Data
Route::prefix('master-data')->name('master_data.')->group(function () {
    Route::get('master-competency-type', [CompetencyTypeController::class, 'index'])
        ->middleware('permission:manage_competency_type')->name('competency_type');

    // A competency is added/edited on its own page rather than in a drawer
    // over the list, so it has its own write endpoints instead of the shared
    // ones below — purely so a successful save can land back on the list. The
    // validation and the writes themselves are the same code.
    Route::middleware('permission:manage_competency')->prefix('master-competency')->group(function () {
        Route::get('/', [CompetencyController::class, 'index'])->name('competency');
        Route::get('create', [CompetencyController::class, 'create'])->name('competency.create');
        Route::post('/', [CompetencyController::class, 'store'])->name('competency.store');
        Route::get('{id}/edit', [CompetencyController::class, 'edit'])->name('competency.edit');
        Route::put('{id}', [CompetencyController::class, 'update'])->name('competency.update');
        // Activate/deactivate trail for one rung of a competency's own
        // proficiency ladder, read from the audit log like the masters'.
        Route::get('levels/{level}/status-history', [CompetencyController::class, 'levelStatusHistory'])
            ->name('competency.levels.statusHistory');
    });

    // The implementation map is on this menu, but writes through the
    // `/idp-setting/implementations` endpoints below.
    Route::get('master-implementation', [CompetencyImplementationController::class, 'index'])
        ->middleware('permission:manage_master_implementation')->name('master_implementation');
});

// --- Competency implementation map writes (screen lives under Master Data) ---
Route::middleware('permission:manage_master_implementation')
    ->prefix('idp-setting/implementations')->name('idp.setting.implementations.')->group(function () {
        Route::post('/', [CompetencyImplementationController::class, 'store'])->name('store');
        Route::put('{implementation}', [CompetencyImplementationController::class, 'update'])->name('update');
        Route::delete('{implementation}', [CompetencyImplementationController::class, 'destroy'])->name('destroy');
        // Same activate/deactivate + on-disk audit trail as the masters.
        Route::put('{implementation}/active', [CompetencyImplementationController::class, 'toggleActive'])->name('active');
        Route::get('{implementation}/status-history', [CompetencyImplementationController::class, 'statusHistory'])->name('statusHistory');
    });

// ---------------------------------------------------------------- IDP Settings
Route::prefix('idp-setting')->name('idp.setting.')->group(function () {

    // --- Development model + its packages ---
    // A package and its weighted models are one form on one page: the models
    // have to total 100%, so they are saved as a set with the package rather
    // than one at a time. There are no per-model endpoints.
    Route::middleware('permission:manage_development_model')->group(function () {
        Route::get('development-model', [IdpSettingController::class, 'developmentModel'])->name('development_model');

        Route::prefix('packages')->name('packages.')->group(function () {
            Route::get('create', [DevelopmentModelPackageController::class, 'create'])->name('create');
            Route::post('/', [DevelopmentModelPackageController::class, 'store'])->name('store');
            Route::get('{developmentModelPackage}/edit', [DevelopmentModelPackageController::class, 'edit'])->name('edit');
            Route::put('{developmentModelPackage}', [DevelopmentModelPackageController::class, 'update'])->name('update');
            Route::delete('{developmentModelPackage}', [DevelopmentModelPackageController::class, 'destroy'])->name('destroy');
        });
    });

    // --- Screens that write through the shared master endpoints ---
    Route::get('master-training', [IdpSettingController::class, 'masterTraining'])
        ->middleware('permission:manage_master_training')->name('master_training');
    Route::get('master-development', [IdpSettingController::class, 'index'])
        ->middleware('permission:manage_master_development')->name('master_development');
    Route::get('review-tools', [IdpSettingController::class, 'reviewTools'])
        ->middleware('permission:manage_review_tools')->name('review_tools');
});

// ------------------------------------------ Shared master writes (any screen)
// {type} selects the table, {id} the row; MasterDataController checks the
// permission of the screen that kind belongs to (MasterDataType::permission()).
Route::middleware('permission:'.MasterDataType::anyPermission())
    ->prefix('idp-setting/masters')->name('idp.setting.masters.')->group(function () {
        Route::post('/', [MasterDataController::class, 'store'])->name('store');
        Route::put('{type}/{id}', [MasterDataController::class, 'update'])->name('update');
        Route::delete('{type}/{id}', [MasterDataController::class, 'destroy'])->name('destroy');
        // Activate / deactivate, plus the audit trail of who did so — which is
        // read back from the log on disk, not from the database.
        Route::put('{type}/{id}/active', [MasterDataController::class, 'toggleActive'])->name('active');
        Route::get('{type}/{id}/status-history', [MasterDataController::class, 'statusHistory'])->name('statusHistory');
    });

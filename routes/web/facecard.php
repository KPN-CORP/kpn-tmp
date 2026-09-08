<?php

use App\Http\Controllers\CompetencyAssessmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\PerformanceAppraisalController;
use App\Http\Controllers\ResultSummaryController;
use Illuminate\Support\Facades\Route;

/*
 * Facecard — the employee list, the profile page, and everything the profile
 * page writes back (nine-box, competency assessment, succession summary). The
 * list and the profile are open to every signed-in user; the writes are gated.
 */

// --- List, exports and bulk PDF ---
Route::prefix('facecard')->name('facecard.')->group(function () {
    Route::get('/', [EmployeeController::class, 'index'])->name('list');
    Route::get('export', [EmployeeController::class, 'exportExcel'])->name('export');

    // Queued zip of every visible employee's facecard; the page polls status.
    Route::prefix('bulk-download')->name('bulk_')->group(function () {
        Route::post('/', [EmployeeController::class, 'bulkDownload'])->name('download');
        Route::get('status/{jobStatus}', [EmployeeController::class, 'bulkStatus'])->name('status');
        Route::get('file/{jobStatus}', [EmployeeController::class, 'bulkFile'])->name('file');
    });
});

// --- One employee's profile ---
// `{employeeId?}` is the catch-all profile route, so it stays last.
Route::prefix('employee')->group(function () {
    Route::get('{employeeId}/pdf', [EmployeeController::class, 'downloadPdf'])->name('facecard.download_pdf');

    Route::name('employee.photo.')->group(function () {
        Route::post('{employeeId}/photo', [EmployeeController::class, 'updatePhoto'])->name('update');
        Route::delete('{employeeId}/photo', [EmployeeController::class, 'deletePhoto'])->name('delete');
    });

    Route::get('{employeeId?}', [EmployeeController::class, 'show'])->name('employee.profile');
});

// --- Nine-box / Performance Appraisal (year-on-year) ---
Route::middleware('permission:input_year_on_year')
    ->prefix('ninebox')->name('ninebox.')->group(function () {
        Route::post('/', [PerformanceAppraisalController::class, 'store'])->name('store');
        Route::put('{appraisal}', [PerformanceAppraisalController::class, 'update'])->name('update');
        Route::delete('/', [PerformanceAppraisalController::class, 'destroy'])->name('destroy');
    });

// --- Competency assessment & succession summary ---
Route::post('competency-assessment', [CompetencyAssessmentController::class, 'store'])
    ->middleware('permission:input_competency_assessment')->name('competency.store');
Route::post('result-summary', [ResultSummaryController::class, 'store'])
    ->middleware('permission:input_successor_position')->name('resultSummary.store');

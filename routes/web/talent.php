<?php

use App\Http\Controllers\ImportController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
 * Talent menu — the cross-employee report and the Import Center.
 */

// --- Reports ---
Route::middleware('permission:view_report_menu')
    ->prefix('report')->name('report.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('show');
        Route::get('export', [ReportController::class, 'exportExcel'])->name('export');
    });

// --- Import Center ---
// The three paths (`import-center`, `import-download`, `import`) are inherited
// from facecard and deliberately left as they are; only the names are grouped.
Route::middleware('permission:view_import_center')->name('import.')->group(function () {
    Route::get('import-center', [ImportController::class, 'index'])->name('index');
    Route::post('import-center/process', [ImportController::class, 'processImport'])->name('process');
    Route::get('import-center/template/{type}', [ImportController::class, 'template'])->name('template');
    Route::get('import-download/{log}', [ImportController::class, 'download'])->name('download');
    Route::delete('import/{log}', [ImportController::class, 'destroy'])->name('destroy');
    Route::delete('import', [ImportController::class, 'destroyAll'])
        ->middleware('permission:delete_all_import_logs')->name('destroy_all');
});

<?php

use App\Http\Controllers\IdpApprovalController;
use App\Http\Controllers\IdpController;
use Illuminate\Support\Facades\Route;

/*
 * IDP (Individual Development Plan) — the employee list, one employee's plans,
 * the documents (PDF / Excel / template), and the staged approval runtime.
 * Visibility is enforced per employee in the service, so no permission gate.
 */

Route::prefix('idp')->name('idp.')->group(function () {
    Route::get('/', [IdpController::class, 'index'])->name('list');

    // Queued zip of every visible employee's IDP; the page polls status.
    Route::prefix('bulk-download')->name('bulk_')->group(function () {
        Route::post('/', [IdpController::class, 'bulkDownload'])->name('download');
        Route::get('status/{jobStatus}', [IdpController::class, 'bulkStatus'])->name('status');
        Route::get('file/{jobStatus}', [IdpController::class, 'bulkFile'])->name('file');
    });

    // Static paths — must stay above the `{employeeId}` routes below.
    Route::get('master-pdf', [IdpController::class, 'downloadMasterPdf'])->name('master_pdf');
    // The signed-in user's own plan (the list above is their team).
    Route::get('my', [IdpController::class, 'mine'])->name('mine');

    // --- One employee's plans: documents, import, and the manage screen ---
    Route::get('{employeeId}/pdf', [IdpController::class, 'downloadPdf'])->name('download_pdf');
    Route::get('{employeeId}/export', [IdpController::class, 'export'])->name('export');
    Route::get('{employeeId}/template', [IdpController::class, 'downloadTemplate'])->name('template.download');
    Route::post('{employeeId}/import', [IdpController::class, 'import'])->name('import.single');
    Route::get('{employeeId}', [IdpController::class, 'show'])->name('show');

    // --- Plan CRUD ---
    Route::post('/', [IdpController::class, 'store'])->name('store');
    Route::put('{idp}', [IdpController::class, 'update'])->name('update');
    Route::delete('{idp}', [IdpController::class, 'destroy'])->name('destroy');

    // --- The two-stage workflow (gated by IDP visibility) ---
    //   planning: the whole plan set, submitted once per package
    //   result:   one program's realization + evidence, submitted per program
    Route::name('approval.')->group(function () {
        Route::post('{employeeId}/submit-planning', [IdpApprovalController::class, 'submitPlanning'])->name('submit_planning');
        Route::post('{idp}/result', [IdpApprovalController::class, 'saveResult'])->name('save_result');
        Route::post('{idp}/submit-result', [IdpApprovalController::class, 'submitResult'])->name('submit_result');
        Route::post('{employeeId}/submit-all-results', [IdpApprovalController::class, 'submitAllResults'])->name('submit_all_results');
    });
});

// --- Approval runtime (staged L1 -> L2 -> ... per request) ---
// Approving is gated on being the current-layer approver, enforced in the
// service, so these need no permission middleware either.
Route::get('approvals', [IdpApprovalController::class, 'inbox'])->name('approvals.inbox');

Route::prefix('idp-approvals')->name('idp.approval.')->group(function () {
    Route::post('{idpApproval}/approve', [IdpApprovalController::class, 'approve'])->name('approve');
    Route::post('{idpApproval}/reject', [IdpApprovalController::class, 'reject'])->name('reject');
});

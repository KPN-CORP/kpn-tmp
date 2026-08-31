<?php

use App\Http\Controllers\ApprovalSettingController;
use App\Http\Controllers\CompetencyAssessmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\IdpApprovalController;
use App\Http\Controllers\IdpController;
use App\Http\Controllers\IdpSettingController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PerformanceAppraisalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResultSummaryController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SsoController;
use App\Http\Controllers\UserGuideController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Temporary placeholder factory. Renders the shared "coming soon" screen with a
 * title. Phase 3 swaps each of these for a real controller; the paths, route
 * names, and permission gates below are already the real ones.
 */
$stub = fn (string $title) => fn () => Inertia::render('Placeholder', ['title' => $title]);

Route::get('dbauth', [SsoController::class, 'dbauth']);

// Everything in the app requires a signed-in user.
Route::middleware('auth')->group(function () use ($stub) {
    Route::get('/', fn () => redirect()->route('facecard.list'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // --- Core (visible to every authenticated user) ---
    Route::get('/facecard', [EmployeeController::class, 'index'])->name('facecard.list');
    Route::get('/facecard/export', [EmployeeController::class, 'exportExcel'])->name('facecard.export');
    Route::post('/facecard/bulk-download', [EmployeeController::class, 'bulkDownload'])->name('facecard.bulk_download');
    Route::get('/facecard/bulk-download/status/{jobStatus}', [EmployeeController::class, 'bulkStatus'])->name('facecard.bulk_status');
    Route::get('/facecard/bulk-download/file/{jobStatus}', [EmployeeController::class, 'bulkFile'])->name('facecard.bulk_file');
    Route::get('/employee/{employeeId}/pdf', [EmployeeController::class, 'downloadPdf'])->name('facecard.download_pdf');
    Route::post('/employee/{employeeId}/photo', [EmployeeController::class, 'updatePhoto'])->name('employee.photo.update');
    Route::delete('/employee/{employeeId}/photo', [EmployeeController::class, 'deletePhoto'])->name('employee.photo.delete');
    Route::get('/employee/{employeeId?}', [EmployeeController::class, 'show'])->name('employee.profile');
    Route::get('/profile', $stub('Profile'))->name('profile');

    // --- User Guide (view open to all; manage gated) ---
    Route::get('/user-guide', [UserGuideController::class, 'index'])->name('user_guide.index');
    Route::get('/user-guide/{userGuide}/download', [UserGuideController::class, 'download'])->name('user_guide.download');
    Route::middleware('permission:manage_user_guide')->group(function () {
        Route::post('/user-guide', [UserGuideController::class, 'store'])->name('user_guide.store');
        Route::delete('/user-guide/{userGuide}', [UserGuideController::class, 'destroy'])->name('user_guide.destroy');
    });

    // --- Nine-box / Performance Appraisal (year-on-year) ---
    Route::middleware('permission:input_year_on_year')->group(function () {
        Route::post('/ninebox', [PerformanceAppraisalController::class, 'store'])->name('ninebox.store');
        Route::put('/ninebox/{appraisal}', [PerformanceAppraisalController::class, 'update'])->name('ninebox.update');
        Route::delete('/ninebox', [PerformanceAppraisalController::class, 'destroy'])->name('ninebox.destroy');
    });

    // --- Competency assessment & succession summary ---
    Route::post('/competency-assessment', [CompetencyAssessmentController::class, 'store'])
        ->middleware('permission:input_competency_assessment')->name('competency.store');
    Route::post('/result-summary', [ResultSummaryController::class, 'store'])
        ->middleware('permission:input_successor_position')->name('resultSummary.store');

    // --- IDP (Individual Development Plan) ---
    Route::get('/idp', [IdpController::class, 'index'])->name('idp.list');
    Route::post('/idp/bulk-download', [IdpController::class, 'bulkDownload'])->name('idp.bulk_download');
    Route::get('/idp/bulk-download/status/{jobStatus}', [IdpController::class, 'bulkStatus'])->name('idp.bulk_status');
    Route::get('/idp/bulk-download/file/{jobStatus}', [IdpController::class, 'bulkFile'])->name('idp.bulk_file');
    Route::get('/idp/master-pdf', [IdpController::class, 'downloadMasterPdf'])->name('idp.master_pdf');
    Route::get('/idp/{employeeId}/pdf', [IdpController::class, 'downloadPdf'])->name('idp.download_pdf');
    Route::get('/idp/{employeeId}/export', [IdpController::class, 'export'])->name('idp.export');
    Route::get('/idp/{employeeId}/template', [IdpController::class, 'downloadTemplate'])->name('idp.template.download');
    Route::post('/idp/{employeeId}/import', [IdpController::class, 'import'])->name('idp.import.single');
    Route::get('/idp/{employeeId}', [IdpController::class, 'show'])->name('idp.show');
    Route::post('/idp', [IdpController::class, 'store'])->name('idp.store');
    Route::put('/idp/{idp}', [IdpController::class, 'update'])->name('idp.update');
    Route::delete('/idp/{idp}', [IdpController::class, 'destroy'])->name('idp.destroy');

    // --- IDP approval runtime (staged L1 → L2 → … per item) ---
    // Submitting is gated by IDP visibility; approving is gated on being the
    // current-layer approver (enforced in the service), so no permission needed.
    Route::post('/idp/{idp}/submit-approval', [IdpApprovalController::class, 'submit'])->name('idp.approval.submit');
    Route::post('/idp/{employeeId}/submit-all-approval', [IdpApprovalController::class, 'submitAll'])->name('idp.approval.submit_all');
    Route::get('/approvals', [IdpApprovalController::class, 'inbox'])->name('approvals.inbox');
    Route::post('/idp-approvals/{idpApproval}/approve', [IdpApprovalController::class, 'approve'])->name('idp.approval.approve');
    Route::post('/idp-approvals/{idpApproval}/reject', [IdpApprovalController::class, 'reject'])->name('idp.approval.reject');

    // --- In-app notifications ---
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read_all');

    // --- Reports ---
    Route::middleware('permission:view_report_menu')->group(function () {
        Route::get('/report', [ReportController::class, 'index'])->name('report.show');
        Route::get('/report/export', [ReportController::class, 'exportExcel'])->name('report.export');
    });

    // --- Import Center ---
    Route::middleware('permission:view_import_center')->group(function () {
        Route::get('/import-center', [ImportController::class, 'index'])->name('import.index');
        Route::post('/import-center/process', [ImportController::class, 'processImport'])->name('import.process');
        Route::get('/import-download/{log}', [ImportController::class, 'download'])->name('import.download');
        Route::delete('/import/{log}', [ImportController::class, 'destroy'])->name('import.destroy');
        Route::delete('/import', [ImportController::class, 'destroyAll'])
            ->middleware('permission:delete_all_import_logs')->name('import.destroy_all');
    });

    // --- Administration ---
    Route::middleware('permission:view_idp_master')->group(function () {
        Route::get('/idp-setting', [IdpSettingController::class, 'index'])->name('idp.setting.index');
        Route::get('/idp-setting/development-model', [IdpSettingController::class, 'developmentModel'])->name('idp.setting.development_model');
        Route::get('/idp-setting/proficiency-level', [IdpSettingController::class, 'proficiencyLevel'])->name('idp.setting.proficiency_level');
        Route::get('/idp-setting/competency', [IdpSettingController::class, 'competency'])->name('idp.setting.competency');
        Route::get('/idp-setting/review-tools', [IdpSettingController::class, 'reviewTools'])->name('idp.setting.review_tools');
        Route::get('/idp-setting/master-implementation', [IdpSettingController::class, 'masterImplementation'])->name('idp.setting.master_implementation');
        Route::get('/idp-setting/master-training', [IdpSettingController::class, 'masterTraining'])->name('idp.setting.master_training');

        Route::post('/idp-setting/packages', [IdpSettingController::class, 'storePackage'])->name('idp.setting.packages.store');
        Route::put('/idp-setting/packages/{developmentModelPackage}', [IdpSettingController::class, 'updatePackage'])->name('idp.setting.packages.update');
        Route::delete('/idp-setting/packages/{developmentModelPackage}', [IdpSettingController::class, 'destroyPackage'])->name('idp.setting.packages.destroy');

        Route::post('/idp-setting/models', [IdpSettingController::class, 'storeModel'])->name('idp.setting.models.store');
        Route::put('/idp-setting/models/{developmentModel}', [IdpSettingController::class, 'updateModel'])->name('idp.setting.models.update');
        Route::delete('/idp-setting/models/{developmentModel}', [IdpSettingController::class, 'destroyModel'])->name('idp.setting.models.destroy');

        Route::post('/idp-setting/masters', [IdpSettingController::class, 'storeMaster'])->name('idp.setting.masters.store');
        // Each kind of master now has its own table, so ids are unique only
        // within a kind: {type} selects the table, {id} the row.
        Route::put('/idp-setting/masters/{type}/{id}', [IdpSettingController::class, 'updateMaster'])->name('idp.setting.masters.update');
        Route::delete('/idp-setting/masters/{type}/{id}', [IdpSettingController::class, 'destroyMaster'])->name('idp.setting.masters.destroy');

        Route::post('/idp-setting/implementations', [IdpSettingController::class, 'storeImplementation'])->name('idp.setting.implementations.store');
        Route::put('/idp-setting/implementations/{implementation}', [IdpSettingController::class, 'updateImplementation'])->name('idp.setting.implementations.update');
        Route::delete('/idp-setting/implementations/{implementation}', [IdpSettingController::class, 'destroyImplementation'])->name('idp.setting.implementations.destroy');
    });

    Route::middleware('permission:view_admin_setting')->group(function () {
        Route::get('/admin/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/admin/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/admin/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/admin/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    // --- Approval Layers (per-employee superior chains) ---
    Route::middleware('permission:view_approval_setting')->group(function () {
        Route::get('/approval-setting', [ApprovalSettingController::class, 'index'])->name('approval.setting.index');
        Route::get('/approval-setting/employees', [ApprovalSettingController::class, 'searchEmployees'])->name('approval.setting.employees');
        Route::post('/approval-setting/import', [ApprovalSettingController::class, 'import'])->name('approval.setting.import');
        Route::get('/approval-setting/{employeeId}/history', [ApprovalSettingController::class, 'history'])->name('approval.setting.history');
        Route::put('/approval-setting/{employeeId}', [ApprovalSettingController::class, 'update'])->name('approval.setting.update');
    });
});

require __DIR__.'/auth.php';

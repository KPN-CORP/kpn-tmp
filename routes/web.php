<?php

use App\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

/*
 * The web routes are split by domain under routes/web/. Each file below is
 * loaded inside the one `auth` group, so none of them repeats that middleware;
 * feature-level permission gates live with the routes they protect.
 */

// Legacy SSO entry point the corporate portal still posts to. The canonical
// `sso/dbauth` route lives in routes/auth.php.
Route::get('dbauth', [SsoController::class, 'dbauth']);

// Everything in the app requires a signed-in user.
Route::middleware('auth')->group(function () {
    require __DIR__.'/web/shell.php';       // entry point, dashboard, notifications
    require __DIR__.'/web/facecard.php';    // employee list, profile, assessments
    require __DIR__.'/web/idp.php';         // development plans + approval runtime
    require __DIR__.'/web/talent.php';      // report, import center
    require __DIR__.'/web/master-data.php'; // master data + IDP settings
    require __DIR__.'/web/admin.php';       // user guide, roles, approval layers
});

require __DIR__.'/auth.php';

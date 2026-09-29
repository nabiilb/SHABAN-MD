<?php

/*
| The REST API, mounted at /api — the same routes, permissions and JSON shapes
| the React app used with the Node API. Every route below "lab.auth" needs a
| session; each declares the permission it needs (permission:a,b = all of them,
| permission:any,a,b = any), and row-level scope (own clinic / assigned
| technician) is enforced again inside the services and policies.
*/

use App\Domain\Permissions as P;
use App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$perm = fn (string ...$keys) => 'permission:'.implode(',', $keys);
$any = fn (string ...$keys) => 'permission:any,'.implode(',', $keys);

/* ---- Public ------------------------------------------------------------ */
Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::get('/health/ready', function () {
    DB::select('SELECT 1');

    return response()->json(['status' => 'ready']);
});

Route::controller(Controllers\AuthController::class)->prefix('auth')->group(function () {
    Route::get('/csrf', 'csrf');
    Route::post('/login', 'login')->middleware('throttle:login');
    Route::post('/refresh', 'refresh');
    Route::post('/logout', 'logout');
    Route::post('/forgot-password', 'forgotPassword')->middleware('throttle:password-reset');
    Route::post('/reset-password', 'resetPassword')->middleware('throttle:password-reset');
});

/* ---- Everything else needs a session ------------------------------------ */
Route::middleware('lab.auth')->group(function () use ($perm, $any) {
    Route::get('/auth/me', [Controllers\AuthController::class, 'me']);

    // Cases
    Route::controller(Controllers\CaseController::class)->group(function () use ($perm, $any) {
        Route::get('/cases', 'index')->middleware($perm(P::CASES_VIEW));
        Route::get('/cases/counts', 'counts');
        Route::post('/cases', 'store')->middleware($any(P::CASES_CREATE, P::CASES_SUBMIT));
        Route::get('/cases/{id}', 'show')->middleware($perm(P::CASES_VIEW));
        Route::patch('/cases/{id}', 'update')->middleware($perm(P::CASES_EDIT));
        Route::delete('/cases/{id}', 'destroy')->middleware($perm(P::CASES_DELETE));
        // Workflow steps: permission, assignment and clinic ownership are checked per step (CasePolicy / shared rules).
        foreach (['status', 'assign', 'qc', 'rework', 'delivery'] as $endpoint) {
            Route::post("/cases/{id}/{$endpoint}", 'workflow')->defaults('endpoint', $endpoint)->middleware($perm(P::CASES_VIEW));
        }
        Route::post('/cases/{id}/notes', 'addNote')->middleware($any(P::CASES_EDIT, P::CASES_UPDATE_STATUS, P::QC_PERFORM, P::CASES_ASSIGN, P::DELIVERY_MANAGE));
        Route::post('/cases/{id}/attachments', 'upload')->middleware($perm(P::FILES_VIEW, P::FILES_UPLOAD));
        Route::delete('/cases/{id}/attachments/{attachmentId}', 'removeAttachment')->middleware($perm(P::CASES_VIEW));
        Route::get('/cases/{id}/attachments/{attachmentId}/download', 'download')->middleware($perm(P::FILES_VIEW));
    });

    // Directory
    foreach ([
        'patients' => [Controllers\PatientController::class, P::PATIENTS_VIEW, P::PATIENTS_CREATE, P::PATIENTS_EDIT, P::PATIENTS_DELETE, [P::PATIENTS_VIEW, P::CASES_SUBMIT]],
        'doctors' => [Controllers\DoctorController::class, P::DOCTORS_VIEW, P::DOCTORS_CREATE, P::DOCTORS_EDIT, P::DOCTORS_DELETE, [P::DOCTORS_VIEW, P::CASES_SUBMIT]],
        'clinics' => [Controllers\ClinicController::class, P::CLINICS_VIEW, P::CLINICS_CREATE, P::CLINICS_EDIT, P::CLINICS_DELETE, [P::CLINICS_VIEW, P::CASES_SUBMIT]],
        'technicians' => [Controllers\TechnicianController::class, P::TECHNICIANS_VIEW, P::TECHNICIANS_CREATE, P::TECHNICIANS_EDIT, P::TECHNICIANS_DELETE, [P::TECHNICIANS_VIEW, P::CASES_ASSIGN]],
    ] as $path => [$controller, $view, $create, $edit, $delete, $listAny]) {
        Route::get("/{$path}", [$controller, 'index'])->middleware($any(...$listAny));
        Route::post("/{$path}", [$controller, 'store'])->middleware($perm($create));
        // Technicians may always open their own profile (checked in the service).
        Route::get("/{$path}/{id}", [$controller, 'show'])->middleware($path === 'technicians' ? [] : [$perm($view)]);
        Route::put("/{$path}/{id}", [$controller, 'update'])->middleware($perm($edit));
        Route::delete("/{$path}/{id}", [$controller, 'destroy'])->middleware($perm($delete));
    }

    // Finance
    Route::controller(Controllers\FinanceController::class)->group(function () use ($perm) {
        Route::get('/invoices', 'invoices')->middleware($perm(P::INVOICES_VIEW));
        Route::post('/invoices', 'createInvoice')->middleware($perm(P::PAYMENTS_RECORD));
        Route::get('/invoices/{id}', 'invoice')->middleware($perm(P::INVOICES_VIEW));
        Route::get('/payments', 'payments')->middleware($perm(P::PAYMENTS_VIEW));
        Route::post('/payments', 'recordPayment')->middleware($perm(P::PAYMENTS_RECORD));
    });

    // Lab floor history
    Route::get('/quality-control', [Controllers\LabController::class, 'qualityChecks'])->middleware($perm(P::QC_VIEW));
    Route::get('/deliveries', [Controllers\LabController::class, 'deliveries'])->middleware($perm(P::DELIVERY_VIEW));

    // Dashboard, reports, search
    Route::controller(Controllers\AnalyticsController::class)->group(function () use ($perm) {
        Route::get('/dashboard', 'dashboard')->middleware($perm(P::DASHBOARD_VIEW));
        Route::get('/reports/cases', 'cases')->middleware($perm(P::REPORTS_VIEW));
        Route::get('/reports/production', 'production')->middleware($perm(P::REPORTS_VIEW));
        Route::get('/reports/technicians', 'technicians')->middleware($perm(P::REPORTS_VIEW));
        Route::get('/reports/clinics', 'clinics')->middleware($perm(P::REPORTS_VIEW));
        Route::get('/reports/financial', 'financial')->middleware($perm(P::REPORTS_VIEW, P::REPORTS_FINANCIAL));
        Route::get('/search', 'search');
    });

    // Notifications (always the caller's own)
    Route::controller(Controllers\NotificationController::class)->group(function () {
        Route::get('/notifications', 'index');
        Route::post('/notifications/read-all', 'markAllRead');
        Route::post('/notifications/{id}/read', 'markRead');
    });

    // Administration
    Route::controller(Controllers\UserController::class)->group(function () use ($perm) {
        Route::get('/users', 'index')->middleware($perm(P::USERS_VIEW));
        Route::post('/users', 'store')->middleware($perm(P::USERS_MANAGE));
        Route::put('/users/{id}', 'update')->middleware($perm(P::USERS_MANAGE));
        Route::patch('/users/{id}/status', 'setStatus')->middleware($perm(P::USERS_MANAGE));
        Route::delete('/users/{id}', 'destroy')->middleware($perm(P::USERS_MANAGE));
    });
    Route::get('/roles', [Controllers\RoleController::class, 'index'])->middleware($any(P::USERS_VIEW, P::ROLES_MANAGE));
    Route::put('/roles/{key}', [Controllers\RoleController::class, 'update'])->middleware($perm(P::ROLES_MANAGE));
    Route::get('/permissions', [Controllers\RoleController::class, 'permissions']);
    Route::controller(Controllers\ServiceCatalogueController::class)->group(function () use ($perm) {
        Route::get('/services', 'index');
        Route::post('/services', 'store')->middleware($perm(P::SERVICES_MANAGE));
        Route::put('/services/{id}', 'update')->middleware($perm(P::SERVICES_MANAGE));
        Route::delete('/services/{id}', 'destroy')->middleware($perm(P::SERVICES_MANAGE));
    });
    Route::get('/settings', [Controllers\SettingsController::class, 'show']);
    Route::put('/settings', [Controllers\SettingsController::class, 'update'])->middleware($perm(P::SETTINGS_MANAGE));
    Route::get('/activity', [Controllers\ActivityController::class, 'index'])->middleware($perm(P::AUDIT_VIEW));
});

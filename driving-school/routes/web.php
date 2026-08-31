<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Instructor;
use App\Http\Controllers\Student;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => Auth::check()
    ? redirect()->route(Auth::user()->homeRoute())
    : redirect()->route('login'))->name('home');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:6,1');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('locale/{locale}', [ProfileController::class, 'switchLocale'])->name('locale.switch');

/*
|--------------------------------------------------------------------------
| Shared authenticated routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
});

/*
|--------------------------------------------------------------------------
| ADMIN — full access to every module
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('dashboard', Admin\DashboardController::class)->name('dashboard');

        Route::resource('students', Admin\StudentController::class);
        Route::resource('instructors', Admin\InstructorController::class);
        Route::resource('vehicles', Admin\VehicleController::class);
        Route::resource('attendance', Admin\AttendanceController::class)->parameters(['attendance' => 'attendance']);
        Route::resource('lessons', Admin\LessonController::class);

        Route::get('transfers', [Admin\TransferController::class, 'index'])->name('transfers.index');
        Route::get('transfers/create', [Admin\TransferController::class, 'create'])->name('transfers.create');
        Route::post('transfers', [Admin\TransferController::class, 'store'])->name('transfers.store');

        /* Finance */
        Route::resource('fuel', Admin\FuelController::class)->parameters(['fuel' => 'fuel']);
        Route::resource('expenses', Admin\ExpenseController::class);
        Route::resource('expense-categories', Admin\ExpenseCategoryController::class)
            ->parameters(['expense-categories' => 'category'])
            ->except('show');
        Route::resource('student-payments', Admin\StudentPaymentController::class)->parameters(['student-payments' => 'payment']);

        Route::resource('loans', Admin\LoanController::class);
        Route::post('loans/{loan}/payments', [Admin\LoanController::class, 'storePayment'])->name('loans.payments.store');

        Route::resource('debts', Admin\CompanyDebtController::class)->parameters(['debts' => 'debt']);
        Route::post('debts/{debt}/cancel', [Admin\CompanyDebtController::class, 'cancel'])->name('debts.cancel');
        Route::post('debts/{debt}/payments', [Admin\DebtPaymentController::class, 'store'])->name('debts.payments.store');
        Route::delete('debts/{debt}/payments/{payment}', [Admin\DebtPaymentController::class, 'destroy'])->name('debts.payments.destroy');
        Route::get('debt-payments', [Admin\DebtPaymentController::class, 'index'])->name('debt-payments.index');

        Route::resource('suppliers', Admin\SupplierController::class);

        /* Reports */
        Route::get('reports', [Admin\ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{report}', [Admin\ReportController::class, 'show'])->name('reports.show');

        /* System */
        Route::resource('users', Admin\UserController::class);
        Route::get('permissions', [Admin\UserController::class, 'permissions'])->name('permissions.index');
        Route::put('permissions/{role}', [Admin\UserController::class, 'updatePermissions'])->name('permissions.update');
        Route::get('audit-logs', [Admin\AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('audit-logs/{auditLog}', [Admin\AuditLogController::class, 'show'])->name('audit-logs.show');
        Route::get('settings', [Admin\SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
    });

/*
|--------------------------------------------------------------------------
| INSTRUCTOR — his own work only. No admin route is reachable from here.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:instructor', 'instructor.profile'])
    ->prefix('instructor')
    ->name('instructor.')
    ->group(function () {
        Route::get('dashboard', Instructor\DashboardController::class)->name('dashboard');

        Route::get('students', [Instructor\StudentController::class, 'index'])->name('students.index');
        Route::get('students/{student}', [Instructor\StudentController::class, 'show'])->name('students.show');

        Route::get('attendance', [Instructor\AttendanceController::class, 'index'])->name('attendance.index');
        Route::get('attendance/check-in', [Instructor\AttendanceController::class, 'create'])->name('attendance.create');
        Route::post('attendance', [Instructor\AttendanceController::class, 'store'])->name('attendance.store');
        Route::get('attendance/{attendance}/edit', [Instructor\AttendanceController::class, 'edit'])->name('attendance.edit');
        Route::put('attendance/{attendance}', [Instructor\AttendanceController::class, 'update'])->name('attendance.update');
        Route::delete('attendance/{attendance}', [Instructor\AttendanceController::class, 'destroy'])->name('attendance.destroy');

        Route::resource('lessons', Instructor\LessonController::class);

        Route::get('vehicles', [Instructor\VehicleController::class, 'index'])->name('vehicles.index');
        Route::get('vehicles/{vehicle}', [Instructor\VehicleController::class, 'show'])->name('vehicles.show');

        Route::get('transfers', [Instructor\TransferController::class, 'index'])->name('transfers.index');
        Route::get('transfers/create', [Instructor\TransferController::class, 'create'])->name('transfers.create');
        Route::post('transfers', [Instructor\TransferController::class, 'store'])->name('transfers.store');

        Route::get('loans', [Instructor\LoanController::class, 'index'])->name('loans.index');
        Route::get('loans/{loan}', [Instructor\LoanController::class, 'show'])->name('loans.show');

        Route::get('reports', [Instructor\ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{report}', [Instructor\ReportController::class, 'show'])->name('reports.show');
    });

/*
|--------------------------------------------------------------------------
| STUDENT — read only, own data only
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:student', 'student.profile'])
    ->prefix('student')
    ->name('student.')
    ->group(function () {
        Route::get('dashboard', Student\DashboardController::class)->name('dashboard');
        Route::get('profile', Student\ProfileController::class)->name('profile');
        Route::get('attendance', Student\AttendanceController::class)->name('attendance');
        Route::get('lessons', Student\LessonController::class)->name('lessons');
        Route::get('progress', Student\ProgressController::class)->name('progress');
    });

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

        // Before the resource route, or {student} would swallow "unpaid".
        Route::get('students/unpaid', [Admin\StudentController::class, 'unpaid'])->name('students.unpaid');
        Route::get('students/no-attendance', [Admin\StudentController::class, 'noAttendance'])->name('students.no-attendance');
        Route::resource('students', Admin\StudentController::class);
        Route::resource('instructors', Admin\InstructorController::class);
        Route::resource('vehicles', Admin\VehicleController::class);
        Route::post('attendance/transfer', [Admin\AttendanceController::class, 'transfer'])->name('attendance.transfer');
        Route::resource('attendance', Admin\AttendanceController::class)->parameters(['attendance' => 'attendance']);
        Route::resource('lessons', Admin\LessonController::class);

        Route::get('transfers', [Admin\TransferController::class, 'index'])->name('transfers.index');
        Route::get('transfers/create', [Admin\TransferController::class, 'create'])->name('transfers.create');
        Route::post('transfers', [Admin\TransferController::class, 'store'])->name('transfers.store');

        /* Finance */
        Route::resource('fuel', Admin\FuelController::class)->parameters(['fuel' => 'fuel']);
        // Releasing an instructor's submission into the company ledger.
        Route::post('fuel/{fuel}/approve', [Admin\FuelController::class, 'approve'])->name('fuel.approve');
        Route::post('fuel/{fuel}/reject', [Admin\FuelController::class, 'reject'])->name('fuel.reject');
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

        /* Training queue and sessions */
        Route::get('training', [Admin\TrainingController::class, 'index'])->name('training.index');
        Route::get('training/board', [Admin\TrainingController::class, 'board'])->name('training.board');
        Route::get('training/queue', [Admin\TrainingController::class, 'queue'])->name('training.queue');
        Route::post('training/queue', [Admin\TrainingController::class, 'storeQueueEntry'])->name('training.queue.store');
        Route::delete('training/queue/{entry}', [Admin\TrainingController::class, 'destroyQueueEntry'])->name('training.queue.destroy');
        Route::post('training/queue/{entry}/move', [Admin\TrainingController::class, 'moveQueueEntry'])->name('training.queue.move');
        Route::get('training/history', [Admin\TrainingController::class, 'history'])->name('training.history');
        Route::get('training/{session}', [Admin\TrainingController::class, 'show'])->name('training.show');

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
        // Moves the student, and today's attendance for them, to another instructor.
        Route::post('attendance/transfer', [Instructor\AttendanceController::class, 'transfer'])->name('attendance.transfer');
        Route::get('attendance/{attendance}/edit', [Instructor\AttendanceController::class, 'edit'])->name('attendance.edit');
        Route::put('attendance/{attendance}', [Instructor\AttendanceController::class, 'update'])->name('attendance.update');
        Route::delete('attendance/{attendance}', [Instructor\AttendanceController::class, 'destroy'])->name('attendance.destroy');

        Route::resource('lessons', Instructor\LessonController::class);

        Route::get('vehicles', [Instructor\VehicleController::class, 'index'])->name('vehicles.index');
        Route::get('vehicles/{vehicle}', [Instructor\VehicleController::class, 'show'])->name('vehicles.show');

        Route::get('transfers', [Instructor\TransferController::class, 'index'])->name('transfers.index');
        Route::get('transfers/create', [Instructor\TransferController::class, 'create'])->name('transfers.create');
        Route::post('transfers', [Instructor\TransferController::class, 'store'])->name('transfers.store');

        /* My Finance — read only, and only what belongs to this instructor. */
        Route::get('fuel', [Instructor\FuelController::class, 'index'])->name('fuel.index');
        Route::get('fuel/create', [Instructor\FuelController::class, 'create'])->name('fuel.create');
        Route::post('fuel', [Instructor\FuelController::class, 'store'])->name('fuel.store');
        Route::get('fuel/{fuel}', [Instructor\FuelController::class, 'show'])->name('fuel.show');

        Route::get('company-debts', [Instructor\CompanyDebtController::class, 'index'])->name('company-debts.index');
        Route::get('company-debts/{companyDebt}', [Instructor\CompanyDebtController::class, 'show'])->name('company-debts.show');

        Route::get('loans', [Instructor\LoanController::class, 'index'])->name('loans.index');
        Route::get('loans/{loan}', [Instructor\LoanController::class, 'show'])->name('loans.show');

        /* Training console */
        Route::get('training', [Instructor\TrainingController::class, 'index'])->name('training.index');
        Route::get('training/board', [Instructor\TrainingController::class, 'board'])->name('training.board');
        // Searches the whole school: any instructor may train any eligible student.
        Route::get('training/students', [Instructor\TrainingController::class, 'searchStudents'])->name('training.students.search');
        Route::patch('training/students/{student}/remaining', [Instructor\TrainingController::class, 'updateRemaining'])
            ->name('training.students.remaining');
        Route::post('training/queue', [Instructor\TrainingController::class, 'addToQueue'])->name('training.queue.store');
        // Closes a waiting cycle, keeping the row. Never deletes.
        Route::delete('training/queue/{entry}', [Instructor\TrainingController::class, 'removeFromQueue'])->name('training.queue.remove');
        Route::post('training/start', [Instructor\TrainingController::class, 'start'])->name('training.start');
        Route::post('training/{session}/end', [Instructor\TrainingController::class, 'end'])->name('training.end');
        // Abandons a session without recording it as training that happened.
        Route::post('training/{session}/cancel', [Instructor\TrainingController::class, 'cancel'])->name('training.cancel');
        Route::post('training/{session}/extend', [Instructor\TrainingController::class, 'extend'])->name('training.extend');
        Route::post('training/{session}/pause', [Instructor\TrainingController::class, 'pause'])->name('training.pause');
        Route::post('training/{session}/resume', [Instructor\TrainingController::class, 'resume'])->name('training.resume');
        Route::post('training/{session}/evaluate', [Instructor\TrainingController::class, 'evaluate'])->name('training.evaluate');

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

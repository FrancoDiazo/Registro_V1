<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CorrectionRequestController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\SyncAttendanceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = request()->user();

    if (! $user) {
        return redirect()->route('login');
    }

    return redirect()->route($user->isAdministrator() ? 'admin.dashboard' : 'dashboard');
});
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
});
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'worker'])->group(function (): void {
    Route::get('/dashboard', [AttendanceController::class, 'index'])->name('dashboard');
    Route::get('/history', [AttendanceController::class, 'history'])->name('history');
    Route::get('/attendance-events/{event}', [AttendanceController::class, 'show'])->name('attendance-events.show');
    Route::post('/correction-requests', [CorrectionRequestController::class, 'store'])->middleware('throttle:10,1')->name('corrections.store');
    Route::post('/api/attendance/sync', SyncAttendanceController::class)->middleware('throttle:30,1')->name('attendance.sync');
});

Route::prefix('admin')->name('admin.')->middleware(['auth', 'administrator'])->group(function (): void {
    Route::get('/', [AdminController::class, 'index'])->name('dashboard');
    Route::post('/employees', [AdminController::class, 'createEmployee'])->middleware('throttle:10,1')->name('employees.store');
    Route::post('/employees/{employee}/status', [AdminController::class, 'updateEmployeeStatus'])->name('employees.status');
    Route::post('/schedules', [AdminController::class, 'createSchedule'])->name('schedules.store');
    Route::post('/assignments', [AdminController::class, 'assignSchedule'])->name('assignments.store');
    Route::post('/corrections/{correction}/resolve', [AdminController::class, 'resolveCorrection'])->name('corrections.resolve');
    Route::post('/incidents/{incident}/review', [AdminController::class, 'reviewIncident'])->name('incidents.review');
    Route::get('/reports.csv', [AdminController::class, 'export'])->name('reports.export');
});

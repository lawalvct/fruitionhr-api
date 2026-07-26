<?php

use App\Modules\Attendance\Controllers\AttendanceController;
use App\Modules\Attendance\Controllers\AttendanceKioskController;
use App\Modules\Attendance\Controllers\AttendanceSettingsController;
use App\Modules\Attendance\Controllers\ShiftAssignmentController;
use App\Modules\Attendance\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

// Shifts
Route::get('shifts', [ShiftController::class, 'index'])->name('v1.shifts.index');
Route::post('shifts', [ShiftController::class, 'store'])->name('v1.shifts.store');
Route::put('shifts/{shift}', [ShiftController::class, 'update'])->name('v1.shifts.update');
Route::delete('shifts/{shift}', [ShiftController::class, 'destroy'])->name('v1.shifts.destroy');
Route::get('shift-assignments', [ShiftAssignmentController::class, 'index'])->name('v1.shift-assignments.index');
Route::post('shift-assignments', [ShiftAssignmentController::class, 'store'])->name('v1.shift-assignments.store');

// Attendance grid + logs + finalize
Route::get('attendance', [AttendanceController::class, 'index'])->name('v1.attendance.index');
Route::get('attendance/import-template.xlsx', [AttendanceController::class, 'importTemplate'])->name('v1.attendance.import-template');
Route::post('attendance-logs', [AttendanceController::class, 'storeLog'])->name('v1.attendance-logs.store');
Route::post('attendance-logs/import', [AttendanceController::class, 'import'])->name('v1.attendance-logs.import');
Route::post('attendance-periods/{period}/finalize', [AttendanceController::class, 'finalize'])->name('v1.attendance.finalize');

// Attendance kiosks (shared QR entrance display)
Route::get('attendance-kiosks', [AttendanceKioskController::class, 'index'])->name('v1.attendance-kiosks.index');
Route::post('attendance-kiosks', [AttendanceKioskController::class, 'store'])->name('v1.attendance-kiosks.store');
Route::put('attendance-kiosks/{kiosk}', [AttendanceKioskController::class, 'update'])->name('v1.attendance-kiosks.update');
Route::delete('attendance-kiosks/{kiosk}', [AttendanceKioskController::class, 'destroy'])->name('v1.attendance-kiosks.destroy');
Route::get('attendance-kiosks/{kiosk}/token', [AttendanceKioskController::class, 'token'])->name('v1.attendance-kiosks.token');

// Attendance settings (self clock-in / kiosk toggles)
Route::get('attendance-settings', [AttendanceSettingsController::class, 'show'])->name('v1.attendance-settings.show');
Route::put('attendance-settings', [AttendanceSettingsController::class, 'update'])->name('v1.attendance-settings.update');

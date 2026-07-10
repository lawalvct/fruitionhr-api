<?php

use App\Modules\Attendance\Controllers\AttendanceController;
use App\Modules\Attendance\Controllers\ShiftAssignmentController;
use App\Modules\Attendance\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

// Shifts
Route::get('shifts', [ShiftController::class, 'index'])->name('v1.shifts.index');
Route::post('shifts', [ShiftController::class, 'store'])->name('v1.shifts.store');
Route::put('shifts/{shift}', [ShiftController::class, 'update'])->name('v1.shifts.update');
Route::delete('shifts/{shift}', [ShiftController::class, 'destroy'])->name('v1.shifts.destroy');
Route::post('shift-assignments', [ShiftAssignmentController::class, 'store'])->name('v1.shift-assignments.store');

// Attendance grid + logs + finalize
Route::get('attendance', [AttendanceController::class, 'index'])->name('v1.attendance.index');
Route::post('attendance-logs', [AttendanceController::class, 'storeLog'])->name('v1.attendance-logs.store');
Route::post('attendance-logs/import', [AttendanceController::class, 'import'])->name('v1.attendance-logs.import');
Route::post('attendance-periods/{period}/finalize', [AttendanceController::class, 'finalize'])->name('v1.attendance.finalize');

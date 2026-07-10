<?php

use App\Modules\Leave\Controllers\LeaveBalanceController;
use App\Modules\Leave\Controllers\LeaveRequestController;
use App\Modules\Leave\Controllers\LeaveTypeController;
use Illuminate\Support\Facades\Route;

// Leave types (settings) — annual allocation carried on the type
Route::get('leave-types', [LeaveTypeController::class, 'index'])->name('v1.leave-types.index');
Route::post('leave-types', [LeaveTypeController::class, 'store'])->name('v1.leave-types.store');
Route::put('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->name('v1.leave-types.update');
Route::delete('leave-types/{leaveType}', [LeaveTypeController::class, 'destroy'])->name('v1.leave-types.destroy');

// Leave requests
Route::get('leave-requests', [LeaveRequestController::class, 'index'])->name('v1.leave-requests.index');
Route::post('leave-requests', [LeaveRequestController::class, 'store'])->name('v1.leave-requests.store');
Route::post('leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('v1.leave-requests.cancel');

// Balances
Route::get('leave-balances', [LeaveBalanceController::class, 'index'])->name('v1.leave-balances.index');
